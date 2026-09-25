# AQA GCSE Computer Science (8525) — assessment structure, content refs and marking conventions

Everything needed to mark an 8525 script without web research. Verify against the source URLs in
§7 only if a figure is disputed or a newer document is needed.

## 1. The two live specifications — read this first

| | Old version | Current version |
|---|---|---|
| Label | "Current specification", v1.2 (29 Nov 2022) | "Updated", **v1.3 (16 June 2025)** |
| First teaching | September 2020 | **September 2025** |
| Exams | June 2022 – June 2026 | **June 2027 onwards** |

Both carry the code 8525, which is why papers must be checked before marking. A resource saying
"GCSE exams June 2022 onwards" or "first teaching 2020" is the old one.

**Content removed in v1.3** (DfE-driven; deletions and rewordings only, nothing added):

| Ref | Removed |
|---|---|
| 3.3.7 Representing sound | the phrase "in a computer" |
| 3.4.5 Systems architecture | **Von Neumann architecture** as a named model; **optical** secondary storage |
| 3.5 Computer networks | **LAN topologies** (star, bus); the requirement to know the **uses of common protocols** beyond the named list; **Ethernet**; **Wi-Fi**; **UDP**; **FTP**; alternative names for the link layer |

Reworded in 3.5: link-layer OS drivers "operate" here (was "sit"); "**TCP** operates at the
transport layer" (was "TCP and UDP").

**Consequence for marking:** an old-spec Paper 2 over-tests relative to v1.3. Void any question on
the above, reduce the paper maximum, and say so. Paper 1 (3.1–3.2) is essentially unaffected and
old papers remain fully usable for it.

## 2. Assessment structure

- **Linear, 100% exam, no coursework, no NEA, and no tiering.** Every candidate sits the same two
  papers and any grade 1–9 is available. There is no Foundation/Higher distinction in this
  subject — do not import one.
- **Paper 1 — Computational thinking and programming skills.** Written, **2 hours, 90 marks, 50%**.
  Content 3.1 and 3.2. Multiple choice, short answer and longer answer. Coded responses are
  written **by hand** in one language, declared at entry: `8525A/1` C#, `8525B/1` Python 3,
  `8525C/1` VB.NET.
- **Paper 2 — Computing concepts.** Written, **1 hour 45 minutes, 90 marks, 50%**. Content 3.3–3.8.
  Multiple choice, short answer, longer answer and **extended response**; includes practical SQL
  writing.
- **Qualification total 180 marks.** Both papers may contain **synoptic** questions drawing on
  content from anywhere in the specification.
- Algorithms in question papers are always presented in the **current AQA pseudo-code**. Where
  pseudo-code is an accepted *response* form, the student may use any clear notation. Where a
  question specifies the form (pseudo-code, program code, flowchart), that form is required.
- Answer booklets print **indentation grids** for code responses. Examiner reports note these are
  frequently misused — a legitimate thing to coach.

## 3. Content references (tag every question with one)

| Ref | Strand | Paper | Key sub-refs |
|---|---|---|---|
| **3.1** | Fundamentals of algorithms | 1 | 3.1.1 representing algorithms (decomposition, abstraction, pseudo-code, flowcharts, trace tables) · 3.1.2 efficiency (time only; no formal complexity) · 3.1.3 linear and binary search · 3.1.4 bubble and merge sort |
| **3.2** | Programming | 1 | 3.2.1 data types · 3.2.2 programming concepts (sequence, selection, definite/indefinite iteration, nesting, identifiers) · 3.2.3 arithmetic incl. DIV/MOD · 3.2.4 relational operators · 3.2.5 Boolean operators · 3.2.6 data structures (1-D and 2-D arrays, records) · 3.2.7 input/output · 3.2.8 string handling · 3.2.9 random numbers · 3.2.10 subroutines, parameters, return values, local variables, structured approach · 3.2.11 validation, authentication, testing, test data types, syntax vs logic errors |
| **3.3** | Fundamentals of data representation | 2 | 3.3.1 number bases · 3.3.2 conversion (**0–255 only**) · 3.3.3 units (decimal prefixes) · 3.3.4 binary arithmetic (≤3 numbers, 8 bits, logical shift only) · 3.3.5 character encoding (7-bit ASCII, Unicode) · 3.3.6 images (size, colour depth, file size) · 3.3.7 sound (sampling rate, resolution, file size) · 3.3.8 compression (Huffman, RLE) |
| **3.4** | Computer systems | 2 | 3.4.1 hardware/software · 3.4.2 Boolean logic (NOT, AND, OR, XOR only; **≤3 inputs**; `.` `+` `⊕` overbar) · 3.4.3 software classification and the OS · 3.4.4 language classification and translators · 3.4.5 systems architecture (ALU, control unit, clock, register, bus; FDE cycle; RAM/ROM/cache/register; **solid state and magnetic storage only**; cloud; embedded) |
| **3.5** | Fundamentals of computer networks | 2 | networks and their advantages · PAN (Bluetooth), LAN, WAN · wired vs wireless · protocols **TCP, IP, HTTP, HTTPS, SMTP, IMAP** · security (authentication, encryption, firewall, MAC filtering) · **4-layer TCP/IP model** |
| **3.6** | Cyber security | 2 | aims · threats: social engineering (blagging, phishing, shouldering), malware (virus, trojan, spyware), pharming, weak/default passwords, misconfigured access rights, removable media, unpatched software, penetration testing · detection and prevention (biometrics, passwords, CAPTCHA, email confirmation, automatic updates) |
| **3.7** | Relational databases and SQL | 2 | tables, records, fields · primary and foreign keys · reducing redundancy and inconsistency · writing and refining SQL |
| **3.8** | Ethical, legal and environmental impacts, incl. privacy | 2 | discursive; the main home of extended response |

Depth limits in bold above are the ones that most often cause over-marking or over-teaching.

> The 3.6, 3.7 and 3.8 sub-reference numbering should be confirmed against the v1.3 PDF the first
> time this skill marks a Paper 2; the AQA web pages render those tables via site JavaScript.
> Tag at strand level (`3.7`) rather than inventing a sub-ref you have not verified.

## 4. Mark-scheme notation

| Code | Meaning |
|---|---|
| **A.** | Accept — this alternative earns the mark. |
| **R.** | Reject — this does not earn the mark even if it looks close. |
| **I.** | Ignore — present but neither earns nor costs. The compile-tolerance list lives here. |
| **NE** | Not enough — insufficient on its own for the mark. |
| **Mark A / Mark B / …** | Named feature marks on a code question; award each independently. |
| **"Max N marks if any errors in code"** | A cap applied after the feature marks are totalled. |
| **Indicative content** | A guide for a banded question, explicitly **not** a checklist. |
| **Level / band descriptors** | The spine of extended-response marking; best fit, then a mark within the band. |

Typical `I.` entries seen in real 8525 schemes: indentation in C# and VB.NET (not Python, where it
is structural), `WriteLine` vs `Write`, a missing `static` in C#, presence or absence of prompt
messages on input statements.

## 5. Marking principles

- **Mark the logic, not the compiler.** AQA's schemes state that minor syntax errors of the kind a
  development environment would flag in the real world should not be penalised. The exam is
  handwritten; logically correct code that would not strictly compile can still earn its marks.
- **Design marks stand independently.** Where a question awards design (a plan, outline
  pseudo-code or a flowchart) as well as code, the design marks survive a broken program.
- **Attempt everything.** Examiner reports state that design marks and marks for straightforward
  variable assignment and Boolean conditions are available even when the overall solution is
  wrong — which is why a blank is strictly worse than a flawed attempt.
- **Terminology.** Where the scheme names a term as the mark, the term is required. Otherwise
  credit the correct idea in the student's own words.
- **Best fit for bands.** Read the whole answer, place it in the band it matches overall, then
  choose the mark within it. Depth on fewer points can outrank breadth without development.
- **Zero for nothing relevant** — but a short, relevant, developed answer is not a zero.

## 6. Available papers and materials

| What | Which spec | Notes |
|---|---|---|
| **Sample assessment materials**, Paper 1 in all three languages + Paper 2 "first exam 2027", with mark schemes | **v1.3** | The only official papers on the current content. Use first. |
| June 2022–2024 past papers, mark schemes, examiner reports | old | Publicly downloadable. Paper 1 fully usable; Paper 2 needs voiding per §1. |
| Most recent series (2025, 2026) | old | Behind centre login (Centre Services / Secure Key Materials) — a private candidate cannot obtain these directly. |
| June 2027 onward | v1.3 | Does not exist yet. |

## 7. Sources (verify only if needed)

- 8525 overview (both spec versions): `https://www.aqa.org.uk/subjects/computer-science/gcse/computer-science-8525`
- v1.3 specification PDF: `https://www.aqa.org.uk/files/d83d2e0a-9266-42c6-9eed-08194ae26a9e`
- Summary of changes (the removals list): `https://www.aqa.org.uk/files/e5e3609b-98ee-4a88-b4ff-da9dc40904cd/faf3ab172b76f13781ac8dbe12783c16915663e0.pdf`
- Subject content index: `https://www.aqa.org.uk/subjects/computer-science/gcse/computer-science-8525/specification/subject-content`
- Assessment resources (SAMs, past papers, mark schemes): `https://www.aqa.org.uk/subjects/computer-science/gcse/computer-science-8525/assessment-resources`
- AQA pseudo-code guide: `https://filestore.aqa.org.uk/resources/computing/AQA-8525-NG-PC.PDF`
- Specimen Paper 1 (Python): `https://filestore.aqa.org.uk/resources/computing/AQA-85251B-SQP-S1.PDF`
- Specimen Paper 1 (VB.NET): `https://filestore.aqa.org.uk/resources/computing/AQA-85251C-SQP-S1.PDF`
- Specimen Paper 1 mark scheme: `https://filestore.aqa.org.uk/resources/computing/AQA-85251-SMS-S1.PDF`
- June 2023 Paper 1 mark scheme: `https://filestore.aqa.org.uk/sample-papers-and-mark-schemes/2023/june/AQA-85251-MS-JUN23.PDF`
- June 2023 Paper 1 examiner report: `https://filestore.aqa.org.uk/sample-papers-and-mark-schemes/2023/june/AQA-85251-WRE-JUN23.PDF`
- Past-paper finder: `https://www.aqa.org.uk/find-past-papers-and-mark-schemes`

The C# specimen (`8525A/1`) follows the same filename pattern but has not been independently
verified — take it from the assessment-resources page rather than editing a URL by hand.
