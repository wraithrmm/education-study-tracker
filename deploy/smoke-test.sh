#!/bin/bash
#
# End-to-end check of the tracker against a running instance.
#
#   bash deploy/smoke-test.sh                 # boots PHP's dev server locally
#   BASE=https://education.rmmann.co.uk bash deploy/smoke-test.sh --remote
#
# Locally it walks the whole connector handshake — dynamic client registration,
# the consent screen, PKCE, the token exchange, refresh rotation — and then
# calls every MCP tool, because "the page loads" has never been the thing that
# breaks.
#
# --remote runs only the checks that read: the write tools would otherwise log
# fictional sessions and attempts into the real record, and the OAuth flow
# would leave a registered client behind on every deploy.
set -uo pipefail

cd "$(dirname "$0")/.."

REMOTE=0
[ "${1:-}" = "--remote" ] && REMOTE=1

WORK="$(mktemp -d)"
FAILURES=0
# Guard the kill on APP_PID actually being set. A --remote run never starts a
# server, and `kill 0` signals the whole process group — which on a CI runner
# means the job itself, so the script passed every check and then took the job
# down with it on the way out.
cleanup() {
    if [ -n "${APP_PID:-}" ]; then
        kill "$APP_PID" 2>/dev/null
        wait "$APP_PID" 2>/dev/null
    fi
    rm -rf "$WORK"
    return 0
}
trap cleanup EXIT

pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; FAILURES=$((FAILURES + 1)); }
check() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$3', got '$2')"; fi; }
# A substring test in the shell, not a pipe into grep -q: grep quits on the
# first match, and once a page outgrows the pipe buffer printf is still
# writing when it does, takes SIGPIPE, and pipefail reports a match as a
# failure — intermittently, and only on the biggest pages.
contains() { if [[ "$2" == *"$3"* ]]; then pass "$1"; else fail "$1 — missing '$3'"; fi; }
lacks() { if [[ "$2" == *"$3"* ]]; then fail "$1 — unexpectedly contains '$3'"; else pass "$1"; fi; }

if [ "$REMOTE" = 1 ]; then
  BASE="${BASE:?set BASE for a remote run}"
  PASSWORD=""
  CURL=(curl -s --max-time 30)
  echo "Testing the live service at $BASE (read-only checks)"
else
  PORT="${SMOKE_PORT:-8110}"
  BASE="http://127.0.0.1:$PORT"
  PASSWORD="php-smoke-password"
  CURL=(curl -s --noproxy '*' --max-time 30)

  mkdir -p "$WORK/tracker-shared/data" "$WORK/site"
  cp -r php/. "$WORK/site/"
  cat > "$WORK/tracker-shared/.env" <<EOF
PUBLIC_URL=$BASE
TRACKER_PASSWORD=$PASSWORD
DASHBOARD_PUBLIC=true
DB_PATH=$WORK/tracker-shared/data/tracker.db
EOF
  # exec, so APP_PID is the php process itself. Without it the trap kills the
  # subshell and leaves the server holding the port, and the next run gets
  # "address already in use" and then 500s on every single check.
  ( cd "$WORK/site" && exec php -S "127.0.0.1:$PORT" index.php > "$WORK/php.log" 2>&1 ) &
  APP_PID=$!
  for _ in $(seq 1 40); do
    "${CURL[@]}" -o /dev/null "$BASE/healthz" && break
    sleep 0.25
  done
  echo "Testing a local instance at $BASE"
fi

echo
echo "== service =="
body="$("${CURL[@]}" "$BASE/healthz")"
contains "/healthz reports ok" "$body" '"ok":true'
# Row counts, so a deploy whose migration produced nothing cannot pass as
# healthy just because the process boots.
contains "/healthz reports the topic count" "$body" '"topics"'
if printf '%s' "$body" | grep -qE '"attempts":[1-9]'; then
  pass "the record holds attempts"
else
  fail "the record holds no attempts: $body"
fi

# The practice tables only exist if schema step 3 ran. /healthz opens the
# store, so a migration that threw would 500 here rather than report counts —
# but naming the table makes the check say what it is actually testing, and
# this runs against production after every deploy.
contains "/healthz reports the practice run count" "$body" '"practice_run"'

code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/")"
check "dashboard index renders" "$code" "200"
code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/s/maths")"
check "maths dashboard renders" "$code" "200"

# The dashboard is the human-readable face of the audit trail, so the trail
# has to reach it and not just the tools. The headings render unconditionally,
# so they are safe to assert against the live record too; the seeded content
# below them is only asserted locally, where the record is known.
body="$("${CURL[@]}" "$BASE/s/maths")"
contains "the subject page links back to the index" "$body" 'All subjects</a>'
contains "the dashboard groups sessions by week" "$body" "Sessions, by week"
contains "the dashboard has an attempts section" "$body" "Papers &amp; checks"
contains "the dashboard links into attempts" "$body" "/a/"
contains "the dashboard links into sessions" "$body" "/session/"
contains "the dashboard links topics to their history" "$body" "/t/"
if [ "$REMOTE" = 0 ]; then
  contains "the dashboard lists the grouped sitting" "$body" "AQA 8300 Foundation, June 2022"
  contains "the dashboard shows the per-paper breakdown" "$body" "10 questions recorded"
fi

# The drill-downs are the auditable views: everything the tools can report,
# the page can now show. Read-only, so they run against production too.
echo
echo "== detail pages =="
attempt_url="$(printf '%s' "$body" | grep -o '/s/[^\"]*/a/[0-9]*' | head -1)"
if [ -n "$attempt_url" ]; then
  page="$("${CURL[@]}" "$BASE$attempt_url")"
  contains "an attempt page renders its papers" "$page" " paper"
  contains "an attempt page links back to its subject" "$page" "/s/maths\""
else
  fail "no attempt link on the dashboard to follow"
fi

session_url="$(printf '%s' "$body" | grep -o '/s/[^\"]*/session/[0-9]*' | head -1)"
if [ -n "$session_url" ]; then
  page="$("${CURL[@]}" "$BASE$session_url")"
  contains "a session page renders what happened" "$page" "What happened"
  contains "a session page renders what it changed" "$page" "What this session changed"
else
  fail "no session link on the dashboard to follow"
fi

board="$("${CURL[@]}" "$BASE/s/maths/practice")"
contains "the practice board renders an activity picker" "$board" 'class="tiles"'
contains "and offers a rolling date window rather than two date boxes" "$board" 'window=30d'
if [ "$REMOTE" = 0 ]; then
  # Whether any activity is still unplayed is a fact about the record, not about
  # the page, so it is only safe where the record is seeded. On production every
  # registered maths activity has runs and no card is an invitation.
  contains "and invites an activity with nothing logged rather than filtering to nothing" \
    "$board" 'not played yet'
fi
contains "and names the activities registered for the subject" "$board" "Tutoring session"

page="$("${CURL[@]}" "$BASE/s/maths/t/A4")"
contains "a topic page renders its change history" "$page" "Every change"
contains "a topic page renders where it was examined" "$page" "In papers"

code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/s/maths/a/999999")"
check "an unknown attempt id is a 404" "$code" "404"
code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/s/maths/t/NOPE")"
check "an unknown topic ref is a 404" "$code" "404"

echo
echo "== discovery =="
body="$("${CURL[@]}" "$BASE/.well-known/oauth-protected-resource")"
contains "protected-resource metadata" "$body" '"resource"'
body="$("${CURL[@]}" "$BASE/.well-known/oauth-protected-resource/mcp")"
contains "protected-resource metadata (suffixed)" "$body" '"resource"'
body="$("${CURL[@]}" "$BASE/.well-known/oauth-authorization-server")"
contains "authorization-server metadata" "$body" '"registration_endpoint"'

# Without this header Claude cannot find the authorisation server, and the
# connector fails with a generic "couldn't reach the MCP server".
headers="$("${CURL[@]}" -o /dev/null -D - -X POST "$BASE/mcp" -H 'content-type: application/json' -d '{}')"
contains "/mcp challenges unauthenticated calls" "$headers" "401"
if printf '%s' "$headers" | grep -qi 'www-authenticate: Bearer resource_metadata='; then
  pass "/mcp 401 carries WWW-Authenticate resource_metadata"
else
  fail "/mcp 401 is missing the WWW-Authenticate resource_metadata header"
fi

if [ "$REMOTE" = 1 ]; then
  echo
  if [ "$FAILURES" -eq 0 ]; then
    echo "SMOKE PASS (read-only checks against $BASE)"
    exit 0
  fi
  echo "SMOKE FAIL: $FAILURES check(s) failed"
  exit 1
fi

echo
echo "== oauth handshake =="
REDIRECT="https://claude.ai/api/mcp/auth_callback"
reg="$("${CURL[@]}" -X POST "$BASE/oauth/register" -H 'content-type: application/json' \
      -d "{\"redirect_uris\":[\"$REDIRECT\"],\"client_name\":\"smoke test\"}")"
CLIENT_ID="$(printf '%s' "$reg" | sed -n 's/.*"client_id":"\([^"]*\)".*/\1/p')"
if [ -n "$CLIENT_ID" ]; then pass "dynamic client registration"; else fail "registration returned no client_id: $reg"; fi

VERIFIER="smoke-test-verifier-$(date +%s)-abcdefghijklmnopqrstuvwxyz"
CHALLENGE="$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=\n')"

code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' \
  "$BASE/oauth/authorize?client_id=$CLIENT_ID&redirect_uri=$REDIRECT&code_challenge=$CHALLENGE&code_challenge_method=S256&state=xyz")"
check "consent screen renders" "$code" "200"

# The consent POST answers with a redirect carrying the authorisation code.
loc="$("${CURL[@]}" -o /dev/null -D - -X POST "$BASE/oauth/authorize" \
  --data-urlencode "password=$PASSWORD" \
  --data-urlencode "client_id=$CLIENT_ID" \
  --data-urlencode "redirect_uri=$REDIRECT" \
  --data-urlencode "code_challenge=$CHALLENGE" \
  --data-urlencode "state=xyz" | grep -i '^location:' | tr -d '\r')"
AUTH_CODE="$(printf '%s' "$loc" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')"
if [ -n "$AUTH_CODE" ]; then pass "consent issues an authorisation code"; else fail "no code in redirect: $loc"; fi
contains "state is preserved" "$loc" "state=xyz"

wrong="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$BASE/oauth/authorize" \
  --data-urlencode "password=definitely-not-the-password" \
  --data-urlencode "client_id=$CLIENT_ID" --data-urlencode "redirect_uri=$REDIRECT" \
  --data-urlencode "code_challenge=$CHALLENGE")"
check "wrong password is rejected" "$wrong" "401"

tok="$("${CURL[@]}" -X POST "$BASE/oauth/token" \
  --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$AUTH_CODE" \
  --data-urlencode "client_id=$CLIENT_ID" \
  --data-urlencode "redirect_uri=$REDIRECT" \
  --data-urlencode "code_verifier=$VERIFIER")"
ACCESS="$(printf '%s' "$tok" | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')"
REFRESH="$(printf '%s' "$tok" | sed -n 's/.*"refresh_token":"\([^"]*\)".*/\1/p')"
if [ -n "$ACCESS" ]; then pass "token exchange with PKCE"; else fail "no access_token: $tok"; fi

reuse="$("${CURL[@]}" -X POST "$BASE/oauth/token" \
  --data-urlencode "grant_type=authorization_code" --data-urlencode "code=$AUTH_CODE" \
  --data-urlencode "client_id=$CLIENT_ID" --data-urlencode "redirect_uri=$REDIRECT" \
  --data-urlencode "code_verifier=$VERIFIER")"
contains "authorisation code is single use" "$reuse" "invalid_grant"

rot="$("${CURL[@]}" -X POST "$BASE/oauth/token" \
  --data-urlencode "grant_type=refresh_token" --data-urlencode "refresh_token=$REFRESH")"
contains "refresh token issues a new access token" "$rot" '"access_token"'
reuse="$("${CURL[@]}" -X POST "$BASE/oauth/token" \
  --data-urlencode "grant_type=refresh_token" --data-urlencode "refresh_token=$REFRESH")"
contains "refresh token is rotated, not reusable" "$reuse" "invalid_grant"

AUTH=(-H "Authorization: Bearer $ACCESS")

echo
echo "== json api =="
body="$("${CURL[@]}" "${AUTH[@]}" "$BASE/api/subjects")"
contains "/api/subjects returns the seeded subject" "$body" '"slug":"maths"'
body="$("${CURL[@]}" "${AUTH[@]}" "$BASE/api/subjects/maths")"
contains "/api/subjects/maths includes topics" "$body" '"topics"'

echo
echo "== mcp =="
rpc() {
  "${CURL[@]}" "${AUTH[@]}" -X POST "$BASE/mcp" -H 'content-type: application/json' -d "$1"
}

body="$(rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}')"
contains "initialize negotiates a protocol version" "$body" '"protocolVersion":"2025-06-18"'
contains "initialize advertises tools" "$body" '"tools"'

body="$(rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')"
for tool in tracker_list_subjects tracker_get_state tracker_review_queue \
            tracker_list_attempts tracker_get_attempt tracker_history \
            tracker_export_markdown tracker_update_topic tracker_log_session \
            tracker_amend_session tracker_log_attempt tracker_create_subject \
            tracker_list_resources tracker_add_resource tracker_remove_resource \
            tracker_log_practice tracker_list_practice tracker_practice_stats \
            tracker_void_practice tracker_get_scoreboard tracker_set_scoreboard \
            tracker_retrieval_due tracker_save_lesson_review tracker_get_lesson_review \
            tracker_list_lesson_reviews tracker_signals tracker_update_signal \
            tracker_review_audit_queue tracker_audit_stamp tracker_week_synthesis_inputs \
            tracker_save_week_synthesis tracker_get_week_synthesis tracker_list_week_syntheses \
            tracker_learner_model; do
  contains "tools/list advertises $tool" "$body" "\"$tool\""
done

# Every tool leads with a USE WHEN line naming the situations that should
# trigger it. Without that the model has to infer relevance from a description
# of mechanics, which is exactly what it gets wrong.
# grep -c counts matching lines, and tools/list is one long line — count the
# occurrences instead.
triggers="$(printf '%s' "$body" | grep -o 'USE WHEN' | wc -l | tr -d ' ')"
tools="$(printf '%s' "$body" | grep -o '"name":"tracker_' | wc -l | tr -d ' ')"
if [ "$triggers" -eq "$tools" ] && [ "$tools" -eq 46 ]; then
  pass "all $tools tool descriptions lead with a USE WHEN trigger"
else
  fail "$triggers of $tools tool descriptions carry a USE WHEN trigger (expected 46 of 46)"
fi

call() { rpc "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }

body="$(call tracker_list_subjects '{}')"
contains "tracker_list_subjects lists maths" "$body" "GCSE Mathematics"

body="$(call tracker_get_state '{"subject":"maths"}')"
contains "tracker_get_state returns the topic table" "$body" "A17"
contains "tracker_get_state reports coverage" "$body" "of the specification covered"

body="$(call tracker_get_state '{"subject":"maths","status":["gap"]}')"
contains "tracker_get_state filters by status" "$body" "Gap"

body="$(call tracker_review_queue '{"subject":"maths"}')"
contains "tracker_review_queue groups the work" "$body" "Priority gaps"

body="$(call tracker_list_attempts '{"subject":"maths"}')"
contains "tracker_list_attempts converts paper grades" "$body" "grade"
contains "topic checks are never grade-converted" "$body" "no grade"

# The three 8300 papers were one sitting. Held as three attempts, each 80-mark
# paper was scaled against the 240-mark boundary table on its own and reported
# a grade for an exam that was only a third sat.
contains "the three 8300 papers are one attempt" "$body" "AQA 8300 Foundation, June 2022"
contains "and that attempt is graded over all 240 marks" "$body" "148/240"
if printf '%s' "$body" | grep -qF '8300/2F Jun-22'; then
  fail "the old one-paper-per-attempt rows are gone"
else
  pass "the old one-paper-per-attempt rows are gone"
fi

body="$(call tracker_export_markdown '{"subject":"maths"}')"
contains "tracker_export_markdown renders the document" "$body" "topic state"
contains "the export carries the attempts" "$body" "## Attempts"
contains "the export carries the session log" "$body" "## Session log"

body="$(call tracker_update_topic '{"subject":"maths","ref":"A17","status":"examready","evidence":"smoke test: spaced retest 5/5 three weeks after securing"}')"
contains "tracker_update_topic moves a topic" "$body" "Exam-ready"

body="$(call tracker_update_topic '{"subject":"maths","ref":"A17","evidence":"short"}')"
contains "evidence under ten characters is refused" "$body" "at least 10 characters"

body="$(call tracker_update_topic '{"subject":"maths","ref":"NOPE","evidence":"a perfectly adequate evidence string"}')"
contains "an unknown topic reference is reported" "$body" "No topic"

body="$(call tracker_log_session '{"subject":"maths","summary":"Smoke test session covering algebra retrieval.","updates":[{"ref":"A4","status":"examready","evidence":"smoke test: 6x2+8x factorisation correct first time"}]}')"
contains "tracker_log_session applies its updates" "$body" "logged for GCSE Mathematics"
contains "tracker_log_session reports what moved" "$body" "A4"

echo
echo "== attempts, papers and questions =="

# An attempt is one sitting. A three-paper mock is one attempt with three
# papers and a single grade across the lot, which is the whole point of the
# shape: one paper of three does not carry a grade.
body="$(call tracker_log_attempt '{"subject":"maths","name":"Smoke three-paper mock","kind":"paper","tier":"F","date":"2026-08-28","papers":[{"code":"8300/1H","score":40,"max":80,"blanks":2},{"code":"8300/2H","score":50,"max":80},{"code":"8300/3H","score":45,"max":80}]}')"
contains "tracker_log_attempt takes several papers as one attempt" "$body" "3 papers"
contains "the grade is computed across the whole attempt" "$body" "135/240"
contains "tracker_log_attempt grades a paper attempt" "$body" "grade"
contains "an attempt without questions says what is missing" "$body" "No question breakdown recorded"
mock_id="$(printf '%s' "$body" | sed -n 's/.*as attempt \([0-9]*\).*/\1/p' | head -1)"
if [ -n "$mock_id" ]; then pass "the attempt reports its id"; else fail "the attempt reports its id"; fi

# Questions carry the marks, the answer given and the topic tested, which is
# what later turns a score into teaching information.
body="$(call tracker_log_attempt '{"subject":"maths","name":"Smoke marked paper","kind":"paper","tier":"F","date":"2026-08-29","papers":[{"code":"8300/1F Nov-22","score":6,"max":10,"blanks":1,"note":"marked question by question","questions":[{"number":"1","score":3,"max":3,"topic_ref":"A17","question":"Solve 3x + 4 = 19","answer":"x = 5","note":"clean"},{"number":"2a","score":3,"max":4,"topic_ref":"A4","answer":"2x(3x+4)","note":"factor not fully taken out"},{"number":"2b","score":0,"max":3,"topic_ref":"A4","answer":"","note":"left blank"}]}]}')"
contains "questions are accepted alongside the paper" "$body" "3 questions recorded"

marked_id="$(printf '%s' "$body" | sed -n 's/.*as attempt \([0-9]*\).*/\1/p' | head -1)"

body="$(call tracker_get_attempt "{\"subject\":\"maths\",\"attempt_id\":$marked_id}")"
contains "tracker_get_attempt groups questions under their paper" "$body" "8300/1F Nov-22"
contains "the answer given is kept" "$body" "2x(3x+4)"
contains "the marker note is kept" "$body" "factor not fully taken out"
contains "tracker_get_attempt breaks the marks down by topic" "$body" "Marks by topic"
contains "the topic that lost the marks is named" "$body" "A4"

body="$(call tracker_get_attempt "{\"subject\":\"maths\",\"attempt_id\":$mock_id}")"
contains "an attempt with no questions still lists its papers" "$body" "8300/2H"
contains "and says the breakdown is missing" "$body" "No question breakdown recorded for this paper"

body="$(call tracker_log_attempt '{"subject":"maths","name":"Smoke split sitting","papers":[{"code":"P1","score":10,"max":20,"sat_on":"2026-08-01"},{"code":"P2","score":12,"max":20,"sat_on":"2026-08-08"}]}')"
contains "papers of one sitting may carry their own dates" "$body" "2 papers"
split_id="$(printf '%s' "$body" | sed -n 's/.*as attempt \([0-9]*\).*/\1/p' | head -1)"
body="$(call tracker_get_attempt "{\"subject\":\"maths\",\"attempt_id\":$split_id}")"
contains "and those dates are reported back" "$body" "sat 2026-08-08"

# A breakdown that does not reconcile with the paper total would make the
# per-topic analysis lie, so it is refused rather than stored.
body="$(call tracker_log_attempt '{"subject":"maths","name":"Smoke mismatch","papers":[{"code":"X1","score":10,"max":20,"questions":[{"number":"1","score":4,"max":20}]}]}')"
contains "questions that do not add up to the paper are refused" "$body" "does not reconcile"

body="$(call tracker_log_attempt '{"subject":"maths","name":"Smoke check","kind":"check","papers":[{"code":"Phase check","score":9,"max":10}]}')"
contains "tracker_log_attempt refuses to grade a check" "$body" "not grade-converted"
body="$(call tracker_log_attempt '{"subject":"maths","name":"Impossible","papers":[{"code":"X","score":90,"max":80}]}')"
contains "a score above the maximum is refused" "$body" "Check the figures"
body="$(call tracker_log_attempt '{"subject":"maths","name":"Impossible question","papers":[{"code":"X","score":5,"max":10,"questions":[{"number":"1","score":9,"max":5}]}]}')"
contains "a question scoring above its own maximum is refused" "$body" "Question 1 on X"
body="$(call tracker_log_attempt '{"subject":"maths","name":"Orphan ref","papers":[{"code":"X","score":2,"max":2,"questions":[{"number":"1","score":2,"max":2,"topic_ref":"NOPE"}]}]}')"
contains "an unknown question topic ref is flagged" "$body" "no topic with reference NOPE"

body="$(call tracker_get_attempt '{"subject":"maths","attempt_id":99999}')"
contains "an unknown attempt id is reported" "$body" "No attempt 99999"

echo
echo "== history and amendment =="

# The trail has to show what a session actually changed, or it records that
# something happened without recording what.
body="$(call tracker_history '{"subject":"maths","weeks":260}')"
contains "tracker_history groups the trail by ISO week" "$body" "week of"
contains "tracker_history lists sessions" "$body" "Session"
contains "tracker_history carries the evidence recorded at the time" "$body" "6x2+8x factorisation correct first time"
contains "a change is attributed to the session that made it" "$body" "(session "
contains "a standalone topic update says so" "$body" "(standalone update)"

body="$(call tracker_history '{"subject":"maths","weeks":260,"ref":"A17"}')"
contains "tracker_history follows one topic" "$body" "A17"
if printf '%s' "$body" | grep -qF '6x2+8x factorisation correct first time'; then
  fail "a single-topic history excludes other topics' changes"
else
  pass "a single-topic history excludes other topics' changes"
fi

sess_id="$(printf '%s' "$(call tracker_history '{"subject":"maths","weeks":260}')" | sed -n 's/.*Session \([0-9]*\)\**.*/\1/p' | head -1)"
body="$(call tracker_amend_session "{\"subject\":\"maths\",\"session_id\":$sess_id,\"summary\":\"Amended: algebra retrieval, corrected record.\"}")"
contains "tracker_amend_session corrects a session" "$body" "amended"
body="$(call tracker_history '{"subject":"maths","weeks":260}')"
contains "the amendment shows in the trail" "$body" "corrected record"

body="$(call tracker_amend_session "{\"subject\":\"maths\",\"session_id\":$sess_id,\"void_reason\":\"logged against the wrong subject\"}")"
contains "a session can be voided with a reason" "$body" "voided"
body="$(call tracker_history '{"subject":"maths","weeks":260}')"
contains "a voided session stays in the trail, marked" "$body" "VOID: logged against the wrong subject"

body="$(call tracker_amend_session "{\"subject\":\"maths\",\"session_id\":$sess_id,\"void_reason\":null}")"
contains "voiding can be undone" "$body" "un-voided"

body="$(call tracker_amend_session '{"subject":"maths","session_id":99999,"summary":"no such session at all"}')"
contains "amending an unknown session is reported" "$body" "No session 99999"
body="$(call tracker_amend_session "{\"subject\":\"maths\",\"session_id\":$sess_id}")"
contains "an amendment with nothing to change is refused" "$body" "at least one of"

echo
echo "== resources =="
body="$(call tracker_list_resources '{"subject":"maths"}')"
contains "an empty resource list says so" "$body" "No resources stored"

body="$(call tracker_add_resource '{"subject":"maths","resources":[{"ref":"A17","title":"BBC Bitesize: Solving linear equations","url":"https://www.bbc.co.uk/bitesize/guides/zt8sgdm/revision/1","kind":"notes","note":"read then do the test"},{"title":"AQA 8300 specification","url":"https://filestore.aqa.org.uk/resources/mathematics/specifications/AQA-8300-SP-2015.PDF","kind":"book"}]}')"
contains "tracker_add_resource stores materials" "$body" "Stored 2 resources"

body="$(call tracker_list_resources '{"subject":"maths","ref":"A17"}')"
contains "a topic lookup returns its resource" "$body" "BBC Bitesize"
contains "a topic lookup includes subject-wide materials" "$body" "AQA 8300 specification"

body="$(call tracker_add_resource '{"subject":"maths","resources":[{"ref":"A17","title":"BBC Bitesize: Solving linear equations","url":"https://example.com/moved","kind":"notes"}]}')"
contains "re-adding the same title updates rather than duplicates" "$body" "Stored 1 resource"
body="$(call tracker_list_resources '{"subject":"maths","ref":"A17"}')"
contains "the updated url replaced the old one" "$body" "example.com/moved"

body="$(call tracker_add_resource '{"subject":"maths","resources":[{"ref":"NOPE","title":"Orphan","kind":"video"}]}')"
contains "an unknown topic ref is flagged but still stored" "$body" "no topic with reference NOPE"

# The queue is what a session opens with, so the materials have to reach it.
body="$(call tracker_review_queue '{"subject":"maths"}')"
contains "the review queue carries subject-wide resources" "$body" "Resources for the whole subject"
contains "and opens with the last session's plan as JSON" "$body" '### last_session'
contains "with next_steps verbatim" "$body" 'next_steps'
contains "and an unfinished group" "$body" "### Unfinished"

body="$(call tracker_retrieval_due '{"subjects":["maths"],"limit":4}')"
contains "tracker_retrieval_due answers for a subject" "$body" "Retrieval due — maths"
contains "and says the intervals in force" "$body" "Intervals in force"
body="$(call tracker_retrieval_due '{"subjects":["nonexistent"]}')"
contains "an unknown subject is reported helpfully" "$body" "Known subjects"

if [ "$REMOTE" = 0 ]; then
  body="$("${CURL[@]}" "$BASE/s/maths")"
  contains "the dashboard lists resources once there are some" "$body" "AQA 8300 specification"
fi

body="$(call tracker_export_markdown '{"subject":"maths"}')"
contains "the export includes a resources section" "$body" "## Resources"

body="$(call tracker_remove_resource '{"subject":"maths","ref":"A17","title":"BBC Bitesize: Solving linear equations"}')"
contains "tracker_remove_resource deletes one" "$body" "Removed"
body="$(call tracker_remove_resource '{"subject":"maths","ref":"A17","title":"BBC Bitesize: Solving linear equations"}')"
contains "removing a missing resource reports it" "$body" "No resource titled"

echo
echo "== practice =="

# Practice is not an attempt: it carries no mark scheme and never touches a
# topic status. Both of those are checked below rather than trusted.
before_state="$(call tracker_get_state '{"subject":"maths"}')"

runs='{"subject":"maths","runs":[
  {"client_run_id":"smoke-1","source":"maths_session","label":"Circle theorems","played_at":"2026-06-01T10:00:00Z","attempted":12,"correct":7,"correct_after_retry":3,"incorrect":2,"duration_seconds":2700,"metrics":{"hints_used":4},"items":[{"topic_ref":"A17","outcome":"correct","attempts_taken":1},{"topic_ref":"A17","outcome":"retry","attempts_taken":2},{"topic_ref":"A4","outcome":"incorrect","attempts_taken":3}]},
  {"client_run_id":"smoke-2","source":"maths_session","label":"Rearranging formulae","played_at":"2026-06-02T10:00:00Z","attempted":8,"correct":6,"correct_after_retry":1,"incorrect":1,"topic_refs":["A5"]},
  {"client_run_id":"smoke-3","source":"maths_session","label":"Mixed starter","played_at":"2026-06-03T10:00:00Z","attempted":5,"correct":5,"incorrect":0}
]}'
body="$(call tracker_log_practice "$runs")"
contains "tracker_log_practice stores a batch of runs" "$body" "3 stored, 0 already recorded"

# The first acceptance test: a retried report must not put a phantom spike in
# the trend line.
body="$(call tracker_log_practice "$runs")"
contains "replaying the identical call stores nothing further" "$body" "0 stored, 3 already recorded"
contains "and reports each run as a duplicate" "$body" "duplicate — already recorded"

body="$(call tracker_log_practice '{"subject":"maths","runs":[{"client_run_id":"smoke-bad","source":"maths_session","label":"Does not add up","attempted":12,"correct":7,"correct_after_retry":3,"incorrect":1}]}')"
contains "a run that does not add up is refused" "$body" "does not add up"
contains "and the refusal names the discrepancy" "$body" "out by 1"

body="$(call tracker_log_practice '{"subject":"maths","runs":[{"client_run_id":"smoke-src","source":"nope_gallery","label":"Unknown activity","attempted":1,"correct":1,"incorrect":0}]}')"
contains "an unregistered source is refused with the registry" "$body" "not registered"

body="$(call tracker_log_practice '{"subject":"maths","runs":[{"client_run_id":"smoke-odd","source":"maths_session","label":"Typos","played_at":"2026-06-04T10:00:00Z","attempted":1,"correct":1,"incorrect":0,"topic_refs":["NOPE"],"metrics":{"unicorns":3}}]}')"
contains "an unknown topic ref is flagged, not refused" "$body" "no topic with reference NOPE"
contains "an unknown metric key is flagged, not refused" "$body" "unicorns on maths_session"
contains "and the run still stores" "$body" "1 stored"

after_state="$(call tracker_get_state '{"subject":"maths"}')"
if [ "$before_state" = "$after_state" ]; then
  pass "logging practice leaves every topic status untouched"
else
  fail "logging practice changed the topic state"
fi

body="$(call tracker_list_practice '{"subject":"maths"}')"
contains "tracker_list_practice lists the runs newest first" "$body" "Circle theorems"
contains "and reports each run's accuracy" "$body" "58.3%"

body="$(call tracker_practice_stats '{"subject":"maths","days":3650}')"
contains "tracker_practice_stats totals the window" "$body" "items attempted"
contains "and reports pooled accuracy rather than a mean of percentages" "$body" "pooled"
contains "and breaks it down by topic, weakest first" "$body" "By topic, weakest first"
contains "joined to the topic status" "$body" "| A17 |"

# Voided runs count towards nothing but stay in the record.
# The tool's table arrives inside a JSON string, so the id is pulled out of
# the row rather than off the start of a line.
run_id="$(printf '%s' "$(call tracker_list_practice '{"subject":"maths","limit":1}')" | grep -o '| [0-9][0-9]* | 2026-' | grep -o '[0-9][0-9]*' | head -1)"
body="$(call tracker_void_practice "{\"subject\":\"maths\",\"run_id\":$run_id,\"void_reason\":\"smoke test: not a real run\"}")"
contains "a practice run can be voided with a reason" "$body" "voided"
body="$(call tracker_list_practice '{"subject":"maths"}')"
contains "a voided run is still listed, marked VOID" "$body" "VOID"
body="$(call tracker_void_practice "{\"subject\":\"maths\",\"run_id\":$run_id,\"void_reason\":null}")"
contains "voiding can be undone" "$body" "un-voided"

body="$(call tracker_void_practice '{"subject":"maths","run_id":99999,"void_reason":"nope"}')"
contains "voiding an unknown run is reported" "$body" "No practice run 99999"

echo
echo "== scoreboards =="
body="$(call tracker_get_scoreboard '{"subject":"maths"}')"
contains "tracker_get_scoreboard returns the panels as JSON" "$body" 'panels'
contains "the maths board carries its split panel" "$body" 'split'

# One invalid panel rejects the whole configuration, so a bad edit cannot
# half-apply and leave a broken board.
body="$(call tracker_set_scoreboard '{"subject":"maths","config":{"version":1,"panels":[{"type":"stat","title":"Runs","metric":"count","window":"all"},{"type":"sparkline","title":"Not a type"}]}}')"
contains "an invalid panel rejects the whole configuration" "$body" "Rejected"
contains "and says the stored one is unchanged" "$body" "stored configuration is unchanged"
body="$(call tracker_get_scoreboard '{"subject":"maths"}')"
contains "the stored configuration really is untouched" "$body" 'split'

body="$(call tracker_set_scoreboard '{"subject":"maths","config":{"version":1,"panels":[{"type":"stat","title":"Runs","metric":"count","window":"all"},{"type":"table","title":"Recent sessions","limit":10,"columns":["date","label","attempted","correct","solve_rate"]}]},"note":"smoke test"}')"
contains "a valid configuration saves" "$body" "saved as version"
body="$(call tracker_get_scoreboard '{"subject":"maths"}')"
contains "and it is what reads back" "$body" 'Recent sessions'

if [ "$REMOTE" = 0 ]; then
  code="$("${CURL[@]}" -o "$WORK/board.html" -w '%{http_code}' "$BASE/s/maths/practice")"
  check "the practice board renders" "$code" "200"
  board="$(cat "$WORK/board.html")"
  contains "the board renders its panels" "$board" "Recent sessions"
  contains "the board offers a card per activity" "$board" 'class="tile"'
  contains "and marks the one the board is showing" "$board" 'aria-current="page"'
  contains "the board lists what was voided and why" "$board" "Practice"
  code="$("${CURL[@]}" -o "$WORK/filtered.html" -w '%{http_code}' "$BASE/s/maths/practice?source=maths_session&from=2026-01-01&ref=A17")"
  check "the board filters by activity, date and topic" "$code" "200"
  filtered="$(cat "$WORK/filtered.html")"
  contains "a filtered board keeps its activity when the dates are edited" \
    "$filtered" '<input type="hidden" name="source" value="maths_session">'
  contains "and opens the date panel because that is what is filtering" \
    "$filtered" '<details class="pickdates" open>'
  body="$("${CURL[@]}" "$BASE/s/maths")"
  contains "the subject dashboard carries a practice panel" "$body" "the whole board"
  contains "which links to the full board" "$body" "/s/maths/practice"
fi

echo
echo "== subjects =="
body="$(call tracker_create_subject '{"slug":"smoke-science","name":"Smoke Science","strands":{"B":"Biology"},"topics":[{"ref":"B1","name":"Cells","strand":"B"}]}')"
contains "tracker_create_subject creates a subject" "$body" "Created Smoke Science"
body="$(call tracker_create_subject '{"slug":"smoke-science","name":"Smoke Science","strands":{"B":"Biology"},"topics":[{"ref":"B1","name":"Cells","strand":"B"},{"ref":"B2","name":"Enzymes","strand":"B"}]}')"
contains "re-running it adds topics without resetting progress" "$body" "existing statuses untouched"

# One field, one call. Correcting an exam date must not mean re-sending a
# syllabus — the risk of disturbing it is the whole reason this path exists.
body="$(call tracker_create_subject '{"slug":"smoke-science","exam_date":"2028-06-01","notes":"Sat in the 2028 series, not 2027. Exact date TO CONFIRM."}')"
contains "a subject's exam date can be corrected on its own" "$body" "Exam date is now 2028-06-01"
contains "and no topics need to be sent to do it" "$body" "No topics were sent"
body="$(call tracker_list_subjects '{}')"
contains "the corrected date is what reads back" "$body" "2028-06-01"
body="$(call tracker_get_state '{"subject":"smoke-science"}')"
contains "and the syllabus is untouched by the correction" "$body" "Enzymes"
# The strand display names only surface in the export, which groups by them.
body="$(call tracker_export_markdown '{"subject":"smoke-science"}')"
contains "and its strand names survive too" "$body" "## Biology"

body="$(call tracker_create_subject '{"slug":"smoke-nothing","name":"Nothing"}')"
contains "creating a subject still needs its strands" "$body" "strands must be"

body="$(call tracker_get_state '{"subject":"nonexistent"}')"
contains "an unknown subject is reported helpfully" "$body" "Known subjects"

echo
echo "== timetable =="
# The seeded database has only maths. The timetable names five subjects, so
# the rest are created first — which is also what happened for real.
for sub in english-literature english-language computer-science spanish; do
  call tracker_create_subject "{\"slug\":\"$sub\",\"name\":\"Smoke $sub\",\"strands\":{\"A\":\"A\"},\"topics\":[{\"ref\":\"A1\",\"name\":\"One\",\"strand\":\"A\"}]}" > /dev/null
done

# Every check below runs against a week that is already over, so `missed` and
# `done` are decided by what was logged rather than by what time the test runs.
SEED=docs/timetable-seed.json
blocks="$(jq -c '[.blocks[] | . + {block_key: .id}]' "$SEED")"
targets="$(jq -c '.targets_hours_per_week' "$SEED")"

body="$(call tracker_set_timetable "{\"blocks\":$blocks,\"valid_from\":\"2024-09-02\",\"note\":\"smoke seed\",\"targets\":$targets}")"
contains "tracker_set_timetable writes the seed" "$body" "36 blocks"
contains "and reports the diff it wrote" "$body" "Added (36)"

# One extra block laid across Monday's maths block, everything else identical.
clash="$(jq -c '[.blocks[] | . + {block_key: .id}] + [{block_key:99,weekday:1,start:"09:50",end:"10:10",kind:"teach",label:"Clash",subjects:["maths"],tracking:"evidence"}]' "$SEED")"
body="$(call tracker_set_timetable "{\"blocks\":$clash,\"valid_from\":\"2024-09-02\"}")"
contains "an overlapping block is refused" "$body" "overlap on Monday"
contains "and the refusal names both blocks" "$body" "Blocks 3"
contains "and nothing is written" "$body" "Nothing was written"

body="$(call tracker_set_timetable '{"blocks":[{"block_key":1,"weekday":1,"start":"09:00","end":"10:00","kind":"teach","label":"X","subjects":["biology"],"tracking":"evidence"}],"valid_from":"2024-09-02"}')"
contains "an unknown subject slug is refused" "$body" "not a tracked subject"

body="$(call tracker_get_timetable '{"valid_on":"2024-09-09"}')"
contains "tracker_get_timetable returns the version in force" "$body" "36 blocks"
contains "and marks how each block is tracked" "$body" "[self_report]"

# Monday 2024-09-09. A maths session fills block 3 and nothing else.
body="$(call tracker_log_session '{"subject":"maths","date":"2024-09-09","summary":"Surds: intro, interleaved practice and an exit ticket","block_key":3,"duration_minutes":70}')"
contains "a session can name the block it fulfilled" "$body" "logged for"

body="$(call tracker_today '{"date":"2024-09-09"}')"
contains "tracker_today attributes the session to block 3" "$body" "<- session #"
lacks "so block 3 is not in the missed list" "$body" ", #3 Maths"
# The session moved no topic, so a teach block was done in the wrong shape:
# still done, said so, and never a miss.
contains "and a teach session with no topic update is done, but not in shape" \
  "$body" "#3 Maths — new topic — expected at least one topic update carrying evidence"
contains "and the block line says done_shape_unmet rather than done" "$body" "done_shape_unmet"
contains "block 2 stays missed: a teaching session is not retrieval practice" \
  "$body" "#2 Retrieval warm-up (mixed)"
contains "block 5 is missed — nothing was logged for it" "$body" "#5 English Literature"

# A practice run on the same day that is NOT retrieval. It must not satisfy the
# retrieval block, and having nowhere to bind it becomes the day's extra work.
# The result of the write is checked: an unasserted write that quietly failed
# would make both of the checks below pass for the wrong reason.
body="$(call tracker_log_practice '{"subject":"maths","runs":[{"client_run_id":"smoke-tt-1","source":"maths_session","label":"Ad-hoc maths drill","played_at":"2024-09-09T09:35:00Z","attempted":10,"correct":8,"incorrect":2,"duration_seconds":840}]}')"
contains "a practice run outside any block is stored" "$body" "1 stored"
body="$(call tracker_today '{"date":"2024-09-09"}')"
contains "a non-retrieval practice source still does not satisfy the retrieval block" \
  "$body" "#2 Retrieval warm-up (mixed)"

# ...and the source that does. A `retrieval` block is judged on the source
# name so that an hour of new teaching can never be counted as spaced
# retrieval; until retrieval_mixed was registered there was no source that
# could satisfy one, and every retrieval block in the week was unreachable.
body="$(call tracker_log_practice '{"subject":"maths","runs":[{"client_run_id":"smoke-retr-1","source":"retrieval_mixed","label":"Retrieval warm-up — mixed","played_at":"2024-09-09T09:05:00Z","attempted":10,"correct":7,"incorrect":3,"duration_seconds":840,"block_key":2}]}')"
contains "a retrieval warm-up can be logged as retrieval" "$body" "1 stored"
body="$(call tracker_today '{"date":"2024-09-09"}')"
lacks "and it satisfies the retrieval block" "$body" "#2 Retrieval warm-up (mixed)"
contains "the retrieval source is registered against no single subject" \
  "$(call tracker_practice_stats '{"subject":"english-literature","days":3650}')" "english-literature"

# The mismatch that the explicit link exists to prevent.
body="$(call tracker_log_session '{"subject":"spanish","date":"2024-09-09","summary":"Spanish vocabulary revision, spaced repetition set","block_key":3}')"
contains "a block_key whose block does not run that subject is refused" "$body" "cannot fulfil it"
contains "and the refusal names both sides" "$body" "runs maths, not spanish"

body="$(call tracker_log_session '{"subject":"maths","date":"2024-09-09","summary":"Maths logged against Friday block on a Monday","block_key":28}')"
contains "a block_key from another weekday is refused" "$body" "No block 28 runs on Monday"

# Ticks: self-reported blocks only.
body="$(call tracker_tick_block '{"date":"2024-09-09","block_key":1,"by":"student"}')"
contains "a self-reported block can be ticked" "$body" "ticked by student"
body="$(call tracker_tick_block '{"date":"2024-09-09","block_key":3,"by":"student"}')"
contains "a study block cannot be ticked" "$body" "judged from logged work"
contains "and the refusal says what to do instead" "$body" "log the session instead"

# Excusals: the parent's reason, shown, and reversible.
body="$(call tracker_excuse_block '{"date":"2024-09-10","block_key":16,"reason":"Dentist — moved to Friday"}')"
contains "a block can be excused with a reason" "$body" "is excused"
body="$(call tracker_week_status '{"week":"2024-W37"}')"
contains "week_status shows the excusal" "$body" "excused — Dentist"
body="$(call tracker_excuse_block '{"date":"2024-09-10","block_key":16,"reason":null}')"
contains "a null reason un-excuses" "$body" "no longer excused"
body="$(call tracker_week_status '{"week":"2024-W37"}')"
lacks "and the block goes back to missed" "$body" "excused — Dentist"

# The alternating Thursday block resolves by ISO week parity.
body="$(call tracker_today '{"date":"2024-09-12"}')"
contains "an odd ISO week resolves the alternating block to maths" "$body" "[maths]"
lacks "and not to computer science" "$body" "[computer-science]"
body="$(call tracker_today '{"date":"2024-09-05"}')"
contains "an even ISO week resolves it to computer science" "$body" "[computer-science]"
lacks "and not to maths" "$body" "[maths]"

# Days off: asked for by her, decided by him.
body="$(call tracker_request_day_off '{"date_from":"2024-09-13","date_to":"2024-09-13","reason":"Cousin'"'"'s birthday","requested_by":"student"}')"
contains "a student's day off is only a request" "$body" "REQUESTED"
contains "and says so plainly" "$body" "This is a request, not a booking"
day_id="$(printf '%s' "$body" | grep -o 'Day off #[0-9]*' | grep -o '[0-9]*' | head -1)"

body="$(call tracker_week_status '{"week":"2024-W37"}')"
contains "a requested day off is shown as undecided" "$body" "day off REQUESTED"
lacks "and its blocks keep being judged until the parent approves" "$body" "day off — Cousin"

body="$(call tracker_decide_day_off "{\"id\":$day_id,\"decision\":\"approve\",\"note\":\"Fine\"}")"
contains "the parent can approve it" "$body" "APPROVED"
body="$(call tracker_week_status '{"week":"2024-W37"}')"
contains "an approved day off clears that day's misses" "$body" "day off — Cousin"
contains "and the day is labelled with its reason" "$body" "day off APPROVED"

body="$(call tracker_decide_day_off "{\"id\":$day_id,\"decision\":\"unapprove\"}")"
contains "approval can be withdrawn" "$body" "being judged again"
body="$(call tracker_week_status '{"week":"2024-W37"}')"
lacks "and the misses come back" "$body" "day off — Cousin"

body="$(call tracker_request_day_off '{"date_from":"2024-10-21","date_to":"2024-10-25","reason":"Half term","requested_by":"parent"}')"
contains "a parent's day off is approved at once" "$body" "booked and approved"

body="$(call tracker_request_day_off '{"date_from":"2024-11-01","date_to":"2024-11-15","reason":"Long trip","requested_by":"student"}')"
contains "a 15-day student request is refused" "$body" "at most 14 days"

body="$(call tracker_week_status '{"week":"2024-W37"}')"
contains "week_status reports hours against the target" "$body" "of 5.00h"
contains "including a subject with nothing logged" "$body" "spanish 0.0h of 1.75h"
contains "and counts the week" "$body" "judged blocks"

body="$(call tracker_days_off '{}')"
contains "tracker_days_off lists them" "$body" "Half term"
contains "and flags what the parent still has to decide" "$body" "awaiting the parent"

echo
echo "== weekly review =="
# One practice run on the Monday, after the maths block is already bound: the
# retrieval block will not take a maths_session run, so it lands as an extra
# and the week has an extra to place and a practice total to report.
call tracker_log_practice '{"subject":"maths","runs":[{"client_run_id":"smoke-weekly-review-1","source":"maths_session","label":"Times tables sprint","played_at":"2024-09-09T16:10:00Z","attempted":20,"correct":17,"incorrect":3,"duration_seconds":720}]}' > /dev/null

body="$(call tracker_week_report '{"week":"2024-W37"}')"
contains "tracker_week_report opens on the week and its dates" "$body" \
  "Week 2024-W37 — 9 September 2024 to 13 September 2024"
contains "the headline counts study blocks only" "$body" "Study blocks 2 of 21 done"
contains "with movement counted separately" "$body" "movement ticked 1 of 5"
contains "and the review block separately again" "$body" "review block not ticked"
contains "the register speaks the one block format" "$body" "#3   09:45-11:00  Maths — new topic"
contains "every missed block is named with day, time, label and what was absent" "$body" \
  "Wed 09:45 Spanish — vocab + listening — no practice logged"
contains "and a timed block says it was an attempt that was missing" "$body" \
  "Tue 13:15 Timed handwritten practice — no attempt logged"
contains "the extra sits on its own day as an extra" "$body" "+ extra: maths practice #"
contains "hours are read against the timetable's planned hours" "$body" "maths 1.93/4.83"
contains "and totalled against the same figure" "$body" "of 13.08 planned hours"
lacks "never against the skills' split" "$body" "/5.00"
contains "the pending day off is put to the parent, not decided" "$body" "do not decide it"
contains "each subject reports its practice" "$body" "practice: 3 runs, 40 attempted"
contains "its coverage" "$body" "coverage 0% at the end of the week"
contains "and the top of its review queue" "$body" "next in the queue:"
contains "movement is reported per subject" "$body" "no topic movement this week"
contains "and it ends with the caution week_status ends with" "$body" "sometimes a logging failure"

# The written half. The note below claims eighteen blocks held; the snapshot the
# server attaches says one. The snapshot is the one that is right.
sections='{"held":"Eighteen of twenty-one study blocks held, or so this note claims.","slipped":"Everything but Monday morning: the week was seeded for the board, not worked.","next":"Tuesday opens with the timed handwritten piece that was missed here.","carry_forward":{"maths":"Surds: the exit ticket to re-run.","english-literature":"The set text was never opened.","english-language":"Creative writing block missed.","computer-science":"Python block missed.","spanish":"Wednesday vocab missed."},"rotation_next":"Lit essay"}'

body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"draft\",\"written_by\":\"routine\",\"sections\":$sections,\"note\":\"written by the smoke run\"}")"
contains "tracker_save_weekly_review writes version 1" "$body" "Saved version 1 (draft) for 2024-W37"
contains "the snapshot is the server's, whatever the note says" "$body" \
  "Snapshot: study blocks 2 of 21 done"
contains "and it points at the page" "$body" "Read it at /week/2024-W37"

body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"draft\",\"written_by\":\"routine\",\"sections\":$sections,\"note\":\"written by the smoke run\"}")"
contains "an identical re-save returns the version already there" "$body" \
  "Version 1 (draft) for 2024-W37 already says exactly this"
contains "and adds no row" "$body" "no version was added"

body="$(call tracker_get_weekly_review '{"week":"2024-W37"}')"
contains "tracker_get_weekly_review reads the draft back" "$body" "version 1 of 1 · draft"
contains "and prints who wrote it as the claim it is" "$body" "written by the Friday routine"
contains "with the sections in the page's order" "$body" "Held: Eighteen of twenty-one"
contains "and the carry-forward line for each subject" "$body" "spanish: Wednesday vocab missed."
lacks "and no drift line, because the record has not moved" "$body" "Since then:"
contains "which it says plainly rather than leaving it silent" "$body" "The record has not moved since"

# The reviewed version, carrying what the parent decided. A decision is
# recorded here; it is never performed here.
reviewed="{\"held\":\"Eighteen of twenty-one study blocks held, or so this note claims.\",\"slipped\":\"Reviewed with Dad: the Wednesday Spanish slot went to the dentist.\",\"next\":\"Tuesday opens with the timed handwritten piece that was missed here.\",\"carry_forward\":{\"maths\":\"Surds: the exit ticket to re-run.\",\"english-literature\":\"The set text was never opened.\",\"english-language\":\"Creative writing block missed.\",\"computer-science\":\"Python block missed.\",\"spanish\":\"Wednesday lost to the dentist.\"},\"rotation_next\":\"Lit essay\",\"decisions\":[{\"kind\":\"day_off\",\"ref\":\"$day_id\",\"decision\":\"deferred\",\"note\":\"Dad has not answered yet.\"},{\"kind\":\"excusal\",\"ref\":\"2024-09-11#20\",\"decision\":\"excused\",\"note\":\"dentist\"}]}"

body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"reviewed\",\"written_by\":\"chat\",\"sections\":$reviewed}")"
contains "saving again writes version 2" "$body" "Saved version 2 (reviewed) for 2024-W37"

body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"draft\",\"written_by\":\"routine\",\"sections\":$sections}")"
contains "a draft after a reviewed version is refused" "$body" "already has a reviewed version"
contains "and the refusal names what to send instead" "$body" "Send stage 'reviewed' instead"

bad="$(printf '%s' "$sections" | sed 's/{"maths"/{"biology":"Not a tracked subject.","maths"/')"
body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"reviewed\",\"written_by\":\"chat\",\"sections\":$bad}")"
contains "a carry_forward key that is not a subject slug is refused" "$body" \
  "sections.carry_forward names"
contains "and the refusal names the slug it refused" "$body" "which is not a tracked subject"
contains "and lists the slugs that do exist" "$body" "Known slugs: maths"
contains "and nothing is written" "$body" "Nothing was written"

bad="$(printf '%s' "$sections" | sed 's/"held":"[^"]*"/"held":""/')"
body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"reviewed\",\"written_by\":\"chat\",\"sections\":$bad}")"
contains "an empty held is refused" "$body" "sections.held is required"

bad="$(printf '%s' "$sections" | sed 's/,"rotation_next":"Lit essay"/,"rotation_next":"Lit essay","decisions":[{"kind":"excusal","ref":"2024-09-09#28","decision":"excused"}]/')"
body="$(call tracker_save_weekly_review "{\"week\":\"2024-W37\",\"stage\":\"reviewed\",\"written_by\":\"chat\",\"sections\":$bad}")"
contains "a decision naming a block that does not run that day is refused" "$body" \
  "No block 28 runs on Monday 2024-09-09"
contains "and the refusal names both sides of the mismatch" "$body" \
  "belongs to another day of the week"

body="$(call tracker_save_weekly_review "{\"week\":\"2099-W01\",\"stage\":\"draft\",\"written_by\":\"routine\",\"sections\":$sections}")"
contains "a week that has not started cannot be reviewed" "$body" "has not started"

# Excusing a block after the note was written is exactly the case the snapshot
# exists for: the note stands, and the drift line says what has moved since.
call tracker_excuse_block '{"date":"2024-09-11","block_key":20,"reason":"Dentist"}' > /dev/null
body="$(call tracker_get_weekly_review '{"week":"2024-W37"}')"
contains "the latest version is the one returned" "$body" "version 2 of 2 · reviewed"
contains "excusing a block after the save makes the drift line appear" "$body" \
  "Since then: Wed 09:45 Spanish — vocab + listening excused — Dentist."
contains "and the drift line quotes the counts the snapshot was written against" "$body" \
  "(19 missed · 0 excused)"
contains "and the decisions it recorded are read back" "$body" "excusal 2024-09-11#20: excused"

body="$(call tracker_get_weekly_review '{"week":"2024-W37","version":1}')"
contains "an earlier version is still readable" "$body" "version 1 of 2 · draft"
contains "and lists the versions that exist" "$body" "Versions: 1 draft"

body="$(call tracker_get_weekly_review '{"week":"2024-W36"}')"
contains "a week with no review says so and names the tool that writes one" "$body" \
  "Write one with tracker_save_weekly_review"

# Lesson reviews over the wire. The behaviour is covered by
# deploy/lesson-review-test.php; this checks the tools answer and that a
# session logged against a teach block without its review is owed one.
body="$(call tracker_list_lesson_reviews '{"subject":"maths","missing":true}')"
contains "tracker_list_lesson_reviews names the session owed a review" "$body" "Sessions that require a review and have none"
body="$(call tracker_signals '{"subject":"maths"}')"
contains "tracker_signals answers with no signals yet" "$body" "No signals match"
body="$(call tracker_review_audit_queue '{"subject":"maths"}')"
contains "tracker_review_audit_queue opens with everything in scope" "$body" "No audit has run for this subject yet"
contains "and lists the session owed a review" "$body" "Sessions owed a review (1)"
body="$(call tracker_get_lesson_review '{"subject":"maths","session_id":1}')"
contains "tracker_get_lesson_review says when there is none" "$body" "has no lesson review"

body="$(call tracker_week_report '{"week":"2024-W99"}')"
contains "a malformed week is refused" "$body" "week must look like"

body="$(call tracker_week_report '{"week":"2024-W20"}')"
contains "a week with no timetable still reports the record" "$body" "No timetable was in force"

if [ "$REMOTE" = 0 ]; then
  # The ladder has now run against a database in production's shape — subjects,
  # sessions, attempts, practice runs and a timetable. Re-opening it must not
  # run any step a second time.
  # The expected version comes from the code, not from a number written here:
  # this check has gone stale twice already on a migration it was meant to
  # be watching.
  fresh="$(php -r '
    define("TRACKER",true); require "php/lib/practice.php"; require "php/lib/store.php";
    $p = tempnam(sys_get_temp_dir(), "sm") . ".db";
    $a = new Store($p); $b = new Store($p);
    echo $b->meta("schema_version");
    @unlink($p);' 2>/dev/null)"
  if [ -n "$fresh" ] && [ "$fresh" -gt 0 ] 2>/dev/null; then
    pass "the migration applies to an empty database, twice over (version $fresh)"
  else
    fail "an empty database did not reach a schema version (got '$fresh')"
  fi

  after="$(SMOKE_DB="$WORK/tracker-shared/data/tracker.db" php -r '
    define("TRACKER",true); require "php/lib/practice.php"; require "php/lib/store.php";
    $a = new Store(getenv("SMOKE_DB")); $b = new Store(getenv("SMOKE_DB"));
    $n = $b->db->query("SELECT count(*) c FROM timetable_versions")->fetch()["c"];
    echo $b->meta("schema_version") . ":" . $n;' 2>/dev/null)"
  check "re-opening a populated database is idempotent, and reaches the same version" \
    "$after" "$fresh:1"
fi

if [ "$REMOTE" = 0 ]; then
  # The board on the page, judged from the same week the tools were checked
  # against. Assertions are on the aria-label text, not on colour: colour is
  # never the only cue, so the words are what has to be right.
  code="$("${CURL[@]}" -o "$WORK/index.html" -w '%{http_code}' "$BASE/")"
  check "the index page renders" "$code" "200"
  page="$(cat "$WORK/index.html")"
  contains "the timetable is on the index page" "$page" 'class="tt"'
  contains "and it leads the page" "$page" "<h1>This week</h1>"
  contains "with the subjects list still below it" "$page" "<h2>Subjects</h2>"
  contains "and the date it thinks it is, in monospace" "$page" 'class="tt-stamp mono"'
  # The class bell is hers to switch, so it ships off and remembers nothing
  # server-side: the page carries the switch and today's blocks, nothing more.
  contains "the class bell is on the board" "$page" 'class="tt-bell" id="ttbell" hidden'
  contains "and it starts off" "$page" 'role="switch" aria-checked="false"'
  contains "with today's blocks for it to keep time against" "$page" 'id="ttbell-data"'

  code="$("${CURL[@]}" -o "$WORK/week.html" -w '%{http_code}' "$BASE/week/2024-W37")"
  check "a past week renders on its own page" "$code" "200"
  week="$(cat "$WORK/week.html")"
  contains "the done block says so in words, shape included" "$week" \
    'aria-label="Maths — new topic 09:45 — done, but not the shape the block asked for: expected at least one topic update carrying evidence; got a session with no topic updates, 70 min recorded"'
  lacks "and it is not a clean tick" "$week" 'aria-label="Maths — new topic 09:45 — done"'
  contains "the missed block says so in words" "$week" 'aria-label="English Literature — set text 11:15 — missed"'
  contains "a ticked movement block is done" "$week" 'aria-label="Move — walk, bike or dance 09:00 — done"'

  # A self-reported block is never marked missed: there is no evidence to
  # derive from, so "not ticked" and "did not happen" are different things.
  # Tuesday's movement block is never ticked anywhere in this run.
  contains "an unticked movement block is optional, not missed" \
    "$week" 'aria-label="Move 09:00 — optional, nothing logged"'
  lacks "so it never gets a red cross" "$week" 'aria-label="Move 09:00 — missed"'
  contains "a break is a rule, not a chip" "$week" 'class="brk"'
  contains "lunch is a labelled slot with its end time" "$week" 'aria-label="Lunch + outside 12:15–13:15"'
  contains "and so is the Thursday group" "$week" 'aria-label="Group 11:45–15:20"'
  lacks "neither carries a status mark" "$week" 'aria-label="Group 11:45 —'
  contains "the done block links to the work that made it done" "$week" '/session/'
  contains "and the week totals are spelled out" "$week" "blocks so far"

  # Extra work is folded away, not dropped: a day with several extras used to
  # make its column two or three times the height of its neighbours, which
  # wrecks the across-the-week comparison the board exists for.
  contains "extra work is a disclosure, not a list" "$week" '<details class="tt-extras">'
  contains "and it says how much there was" "$week" "2 extra</span></summary>"
  lacks "and it starts closed" "$week" '<details class="tt-extras" open>'

  # A missed block must not offer a way to log work: the page is public and
  # unauthenticated, so there is nothing safe for it to link to.
  missed_link="$(printf '%s' "$week" | grep -o '<a class="blk s-missed"' | wc -l | tr -d ' ')"
  check "a missed block links nowhere" "$missed_link" "0"


  # There is one board. The status vocabulary it speaks is the same on the
  # index page and on any other week's page, because both render the one
  # component from the one judge.
  contains "the week page is the week strip" "$week" 'class="wk"'
  lacks "but a past week has no bell to ring" "$week" 'id="ttbell"'
  contains "and it prints" "$week" '@media print'

  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/week/not-a-week")"
  check "a malformed week is a 404" "$code" "404"

  # ---- the weekly report, on the page --------------------------------------
  # The ledger half: the page must read the same partitioned counts the tools
  # reported, name every miss in words, and never make colour the only cue.
  report="$(call tracker_week_report '{"week":"2024-W37"}')"
  fraction="$(printf '%s' "$report" | grep -o 'Study blocks [0-9]* of [0-9]* done' \
    | head -1 | sed 's/Study blocks //;s/ done//')"
  code="$("${CURL[@]}" -o "$WORK/report.html" -w '%{http_code}' "$BASE/week/2024-W37")"
  check "the weekly report renders" "$code" "200"
  week="$(cat "$WORK/report.html")"
  contains "the headline counts study blocks only, exactly as the tools count them" \
    "$week" "<p class=\"big mono\">$fraction</p>"
  contains "with movement counted separately" "$week" "movement ticked 1 of 5"
  contains "and the review block separately again" "$week" "review block not ticked"
  contains "the segmented bar says the counts in words, not in colour" "$week" \
    'aria-label="21 study blocks: 2 done, 1 of the done not in shape, 18 missed, 1 excused"'
  contains "hours are read against the timetable's planned hours" "$week" "1.93 / 4.83"
  contains "and the bar says which side of the plan it is on, in words" "$week" \
    "under the timetable"

  contains "a missed block is named with its day and time" "$week" \
    'Tue 10 Sep · 13:15–14:15'
  contains "and with its label" "$week" "<b>Timed handwritten practice</b>"
  contains "and with what was absent" "$week" "no attempt logged that day"
  contains "a missed Spanish block says practice was what was missing" "$week" \
    "no practice logged that day"
  contains "the excused block shows the parent's reason" "$week" \
    'aria-label="Spanish — vocab + listening 09:45 — excused: Dentist"'
  lacks "and an excused block is not named as missed" "$week" \
    '<b>Spanish — vocab + listening</b> — no practice logged'
  contains "the extra is on the page as an extra" "$week" \
    'aria-label="extra work, outside the timetable"'
  contains "the decisions are read-only, decided in the chat" "$week" \
    "Decided in the review chat, never here"
  contains "and the recorded decision names what Dad said" "$week" "excused — dentist"

  # The written half: the stamp, the versions, and the drift between them.
  contains "the saved review leaves its stamp on the page" "$week" '<p class="stamp"'
  contains "and the stamp says it was reviewed" "$week" "Reviewed with Dad"
  contains "the margin carries what was written" "$week" \
    "Reviewed with Dad: the Wednesday Spanish slot went to the dentist."
  contains "and one carry-forward line per subject" "$week" "Wednesday lost to the dentist."
  contains "excusing a block after the save puts the drift line on the page" "$week" \
    "Since then: Wed 09:45 Spanish — vocab + listening excused — Dentist."
  contains "and the drift line quotes the counts the note was written against" "$week" \
    "(19 missed · 0 excused)"

  v1="$("${CURL[@]}" "$BASE/week/2024-W37?v=1")"
  contains "?v=1 shows the first version" "$v1" "Eighteen of twenty-one study blocks held"
  contains "and stamps it as the draft it is" "$v1" '<p class="stamp draft"'
  contains "with both version chips" "$v1" 'aria-current="true"'
  contains "and the later one still a link" "$v1" '/week/2024-W37?v=2'

  # A week whose note was written against the record as it stands says nothing
  # about drift at all: silence means the two still agree.
  call tracker_log_session '{"subject":"maths","date":"2024-09-16","summary":"Maths consolidation, cut short after twenty minutes","block_key":3,"duration_minutes":20}' > /dev/null
  call tracker_log_session '{"subject":"maths","date":"2024-08-28","summary":"Algebra revision, logged before the timetable existed","duration_minutes":45}' > /dev/null
  clean='{"held":"Monday ran, and the maths block was logged even though it was cut short.","slipped":"The block was twenty minutes of the seventy-five it was given.","next":"Tuesday opens with the timed handwritten piece.","carry_forward":{"maths":"Finish the consolidation block that was cut short."},"rotation_next":"Maths section"}'
  body="$(call tracker_save_weekly_review "{\"week\":\"2024-W38\",\"stage\":\"draft\",\"written_by\":\"routine\",\"sections\":$clean}")"
  contains "a note can be saved for the following week" "$body" "Saved version 1 (draft) for 2024-W38"
  w38="$("${CURL[@]}" "$BASE/week/2024-W38")"
  contains "a short block is named with the minutes it actually ran" "$w38" \
    "20 minutes of 75 logged"
  contains "and is not counted as plainly done" "$w38" "1 short"
  contains "the draft stamp is dashed and says who claims to have written it" "$w38" \
    '<p class="stamp draft"'
  lacks "no drift line when the note still matches the record" "$w38" "Since then:"
  contains "which is silence, not a sentence saying nothing changed" "$w38" \
    "Written from the record at"

  # The term ledger.
  ledger="$("${CURL[@]}" "$BASE/weeks?from=2024-W38")"
  contains "/weeks lists the week" "$ledger" "2024-W37"
  contains "and links to it" "$ledger" 'href="/week/2024-W37"'
  contains "and carries its stamp" "$ledger" 'class="ministamp"'
  contains "with the same study-block fraction the week page shows" "$ledger" ">1/21<"
  contains "and a mark saying the record has moved since the note" "$ledger" \
    'aria-label="the record has moved since this note was written"'
  contains "weeks before the first timetable say so" "$ledger" "no timetable yet"
  contains "and still show the work that was logged in them" "$ledger" "0.8 / —"
  contains "the ledger says the Review column is the only written one" "$ledger" \
    "the only thing a person or the routine ever writes"

  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/week/2026-W99")"
  check "an impossible week falls back to the ledger with a 404" "$code" "404"
  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/weeks/nonsense")"
  check "and /weeks takes no path under it" "$code" "404"

  # ---- the parent's controls ---------------------------------------------
  #
  # The board stays readable by anyone with the link; writing to it does not.
  # Every one of these checks is the difference between a family's record and
  # a public guestbook.
  JAR="$WORK/cookies.txt"
  n_of() { printf '%s' "$1" | grep -o "$2" | wc -l | tr -d ' '; }

  anon="$("${CURL[@]}" "$BASE/")"
  check "signed out, the board offers no block controls" "$(n_of "$anon" '<details class="blockctl"')" "0"
  check "signed out, no day-off controls either" "$(n_of "$anon" 'action="/tt/day"')" "0"
  check "and no CSRF token is handed out" "$(n_of "$anon" 'name=\"csrf\"')" "0"
  contains "but the way in is findable" "$anon" "Sign in to edit"

  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$BASE/tt/block" \
    -d 'date=2024-09-09&block_key=3&action=done')"
  check "a write with no cookie is refused" "$code" "403"
  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$BASE/tt/day" -d 'date=2024-09-09')"
  check "so is a day off with no cookie" "$code" "403"

  code="$("${CURL[@]}" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/login" -d 'password=wrong')"
  check "the wrong password does not sign you in" "$code" "401"
  code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$BASE/tt/block" -b "$JAR" \
    -d 'date=2024-09-09&block_key=3&action=done')"
  check "and leaves no usable cookie behind" "$code" "403"

  code="$("${CURL[@]}" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/login" \
    -d "password=$PASSWORD&next=/")"
  check "the right password signs you in" "$code" "303"

  auth="$("${CURL[@]}" -b "$JAR" "$BASE/")"
  if [ "$(n_of "$auth" '<details class="blockctl"')" -gt 0 ]; then
    pass "signed in, every block carries a control"
  else
    fail "signed in, no block controls rendered"
  fi
  contains "and the day headers offer a day off" "$auth" 'action="/tt/day"'
  contains "and it says who you are" "$auth" "Signed in as Dad"
  CSRF="$(printf '%s' "$auth" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | grep -o '[a-f0-9]\{32\}')"
  if [ -n "$CSRF" ]; then pass "a CSRF token is issued"; else fail "no CSRF token on the page"; fi

  code="$("${CURL[@]}" -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/tt/block" \
    -d 'date=2024-09-09&block_key=5&action=done')"
  check "a signed-in write still needs its CSRF token" "$code" "403"
  code="$("${CURL[@]}" -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/tt/block" \
    -d "csrf=notthetoken&date=2024-09-09&block_key=5&action=done")"
  check "and a wrong one is no better" "$code" "403"

  # Marked done without evidence: carried, but never dressed up as evidence.
  "${CURL[@]}" -b "$JAR" -o /dev/null -X POST "$BASE/tt/block" \
    -d "csrf=$CSRF&date=2024-09-09&block_key=5&action=done&note=Read it on the sofa&next=/"
  week="$("${CURL[@]}" -b "$JAR" "$BASE/week/2024-W37")"
  contains "a block can be marked done without evidence" "$week" \
    'marked done by Dad; no work was logged: Read it on the sofa'
  lacks "and it is not passed off as a derived done" "$week" \
    'aria-label="English Literature — set text 11:15 — done"'
  contains "the totals name it separately" "$week" "marked by hand"

  "${CURL[@]}" -b "$JAR" -o /dev/null -X POST "$BASE/tt/block" \
    -d "csrf=$CSRF&date=2024-09-09&block_key=7&action=skip&note=Dentist&next=/"
  week="$("${CURL[@]}" -b "$JAR" "$BASE/week/2024-W37")"
  contains "a block can be marked skipped, with the reason kept" "$week" \
    'aria-label="Computer Science — Python 13:15 — excused: Dentist"'

  "${CURL[@]}" -b "$JAR" -o /dev/null -X POST "$BASE/tt/block" \
    -d "csrf=$CSRF&date=2024-09-09&block_key=7&action=clear&next=/"
  week="$("${CURL[@]}" -b "$JAR" "$BASE/week/2024-W37")"
  lacks "and clearing puts it back to what the evidence says" "$week" \
    'aria-label="Computer Science — Python 13:15 — excused: Dentist"'

  "${CURL[@]}" -b "$JAR" -o /dev/null -X POST "$BASE/tt/day" \
    -d "csrf=$CSRF&date=2024-09-11&reason=Grandma visiting&next=/"
  week="$("${CURL[@]}" -b "$JAR" "$BASE/week/2024-W37")"
  contains "a whole day can be marked off from the board" "$week" \
    '<b>Day off</b>Grandma visiting'
  contains "and its blocks stop counting as missed" "$week" "day off"

  "${CURL[@]}" -b "$JAR" -o /dev/null -X POST "$BASE/tt/day" \
    -d "csrf=$CSRF&date=2024-09-11&action=clear&next=/"
  week="$("${CURL[@]}" -b "$JAR" "$BASE/week/2024-W37")"
  lacks "and the day off can be undone" "$week" \
    '<b>Day off</b>Grandma visiting'

  # What the public sees of all that: the result, and no way to change it.
  anon="$("${CURL[@]}" "$BASE/week/2024-W37")"
  contains "the public view shows what the parent recorded" "$anon" "marked done by Dad"
  check "but still offers no controls" "$(n_of "$anon" '<details class="blockctl"')" "0"

  code="$("${CURL[@]}" -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/logout")"
  check "signing out works" "$code" "303"
  after="$("${CURL[@]}" -b "$JAR" "$BASE/")"
  check "and the controls go with it" "$(n_of "$after" '<details class="blockctl"')" "0"

  # And the report reads the same block the same way: what Dad asserted is
  # accounted for, named as his word, and never counted as a miss.
  report="$("${CURL[@]}" "$BASE/week/2024-W37")"
  contains "the report names the hand-marked block under marked by hand" "$report" \
    '<b>English Literature — set text</b> — marked done by Dad, no work was logged'
  lacks "and it is not in the MISSED list" "$report" \
    '<b>English Literature — set text</b> — no session logged that day'
  contains "and the bar counts it apart from both done and missed, in words" "$report" \
    'aria-label="21 study blocks: 2 done, 1 of the done not in shape, 1 marked by hand, 17 missed, 1 excused"'
fi

echo
if [ "$FAILURES" -eq 0 ]; then
  echo "SMOKE PASS"
  exit 0
fi
echo "SMOKE FAIL: $FAILURES check(s) failed"
[ "$REMOTE" = 0 ] && sed -n '1,40p' "$WORK/php.log"
exit 1
