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
contains() { if printf '%s' "$2" | grep -qF "$3"; then pass "$1"; else fail "$1 — missing '$3'"; fi; }

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

code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/")"
check "dashboard index renders" "$code" "200"
code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/s/maths")"
check "maths dashboard renders" "$code" "200"

# The dashboard is the human-readable face of the audit trail, so the trail
# has to reach it and not just the tools. The headings render unconditionally,
# so they are safe to assert against the live record too; the seeded content
# below them is only asserted locally, where the record is known.
body="$("${CURL[@]}" "$BASE/s/maths")"
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
            tracker_list_resources tracker_add_resource tracker_remove_resource; do
  contains "tools/list advertises $tool" "$body" "\"$tool\""
done

# Every tool leads with a USE WHEN line naming the situations that should
# trigger it. Without that the model has to infer relevance from a description
# of mechanics, which is exactly what it gets wrong.
# grep -c counts matching lines, and tools/list is one long line — count the
# occurrences instead.
triggers="$(printf '%s' "$body" | grep -o 'USE WHEN' | wc -l | tr -d ' ')"
tools="$(printf '%s' "$body" | grep -o '"name":"tracker_' | wc -l | tr -d ' ')"
if [ "$triggers" -eq "$tools" ] && [ "$tools" -eq 15 ]; then
  pass "all $tools tool descriptions lead with a USE WHEN trigger"
else
  fail "$triggers of $tools tool descriptions carry a USE WHEN trigger (expected 15 of 15)"
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
echo "== subjects =="
body="$(call tracker_create_subject '{"slug":"smoke-science","name":"Smoke Science","strands":{"B":"Biology"},"topics":[{"ref":"B1","name":"Cells","strand":"B"}]}')"
contains "tracker_create_subject creates a subject" "$body" "Created Smoke Science"
body="$(call tracker_create_subject '{"slug":"smoke-science","name":"Smoke Science","strands":{"B":"Biology"},"topics":[{"ref":"B1","name":"Cells","strand":"B"},{"ref":"B2","name":"Enzymes","strand":"B"}]}')"
contains "re-running it adds topics without resetting progress" "$body" "existing statuses untouched"

body="$(call tracker_get_state '{"subject":"nonexistent"}')"
contains "an unknown subject is reported helpfully" "$body" "Known subjects"

echo
if [ "$FAILURES" -eq 0 ]; then
  echo "SMOKE PASS"
  exit 0
fi
echo "SMOKE FAIL: $FAILURES check(s) failed"
[ "$REMOTE" = 0 ] && sed -n '1,40p' "$WORK/php.log"
exit 1
