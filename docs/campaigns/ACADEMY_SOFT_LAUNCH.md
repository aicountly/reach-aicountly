# Aicountly Academy soft launch — Reach runbook (L12)

Launch day: **2026-10-25**. Plan: `academy-aicountly/docs/ALIGNMENT_PLAN.md` §2.5, items L11 (code,
branch `claude/academy-alignment`) and L12 (this runbook: data, staff session).

What is launched: **Aicountly Academy** (`https://www.aicountly.com/academy`) and its two courses,
run end to end by Aicountly (admissions, tests, project evaluation, certificates):

| Product | Slug | Shape | Public page |
| --- | --- | --- | --- |
| Aicountly Academy | `academy` | the training arm | https://www.aicountly.com/academy |
| Aicountly Edge | `edge` | long-term, 6–12 month hands-on accounting programme for college students | https://www.aicountly.com/edge |
| Aicountly Aspire | `aspire` | short-term workshops and courses, at colleges or open | https://www.aicountly.com/aspire |

Rules for every word below and everything the bot writes: write the brand **Aicountly** (mixed
case), never call a course "Beta" or software, **no fee amounts** (the site says "Fee shared on
admission"), **no placement, job, internship, salary or result promises**, and the certificate is
an Aicountly Academy certificate (never a degree, diploma or recognised qualification).

Run the steps in order. Nothing here was run against the live Reach API; every id below
(`<EDGE_CAMPAIGN_ID>` …) is the one the previous step returned.

---

## 0. Before you start

1. **Deploy** `claude/academy-alignment` and run the migrations (`php spark migrate`). That ships:
   - `ProductCatalog`: `academy`, `edge`, `aspire` as kind **course** (status `course`, never
     Beta). A blog with product_slug `edge` / `aspire` / `academy` is accepted without the word
     "Beta". Courses get no release notes or workflow guides.
   - Vetting context (all rubrics, social posts, videos, blog/KB routine, reach-command brief):
     "Aicountly builds accounting & business software and runs Aicountly Academy …", plus the
     course claim rules (no fees, no placement promises).
   - Link registry: `/academy`, `/edge`, `/aspire` (category `academy`; suggested only under
     academy articles, never as evergreen "Related reading" under a GST post).
   - `brand.offer` code default now ends with the Academy line (step 6).
   - Brand casing: prompts, seeds and seeded rows now write "Aicountly".
2. **Sign in** to https://reach.aicountly.org through Console. Every call below is a staff-session
   call:

   ```bash
   REACH=https://reach.aicountly.org/api
   TOKEN='<the reach_token value from the signed-in browser: DevTools → Application → Local Storage → reach.aicountly.org>'
   H=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json')
   ```

   Responses are `{"ok":true,"data":…}`; errors are `{"ok":false,"error":"…"}`. The role needs
   `product.manage`, `knowledge.submit`, `knowledge.approve`, `persona.manage`,
   `distribution.segment`, `campaign.create`, `campaign.approve`, `campaign.dispatch`,
   `settings.manage` and `bot.command` (a Reach admin has all of them). Each step names the UI
   screen as an alternative.
3. **Engage** (L4) must have product codes `academy`, `edge` and `aspire`: Reach pushes captured
   leads with `interested_product` = `product_interest`, so the codes must match exactly.

---

## 1. Knowledge products (Knowledge → Products)

Look first — `POST` makes `edge-2` if `edge` already exists:

```bash
for s in academy edge aspire; do curl -s "${H[@]}" "$REACH/v1/knowledge/products?q=$s" | jq '.data.items[] | {id, slug, status}'; done
```

**Path A (preferred, one call):** the taxonomy import now also creates the three Academy rows
(status `needs_review`, name, public page and a one-line summary; idempotent, never touches an
approved row; it also creates any missing SaaS rows as `needs_review`):

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products/import-taxonomy" | jq .data
```

Then for each of the three rows: fill the description (allowed while `needs_review`) and approve.

**Path B (only if the rows do not exist and you do not want the import):** create as `draft`,
then submit:

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products" -d '{
  "slug": "edge",
  "name": "Aicountly Edge",
  "short_description": "Long-term (6–12 month) hands-on accounting training programme for college students, run by Aicountly Academy.",
  "description": "<p>Aicountly Edge is a long-term, hands-on accounting training programme for college students, run end to end by Aicountly Academy. Admission is by application. Students work on practical accounting tasks and a project; Aicountly evaluates the work, conducts the tests and issues an Aicountly Academy certificate to students who meet the programme requirements. Fees are shared on admission.</p>",
  "public_url": "https://www.aicountly.com/edge"
}' | jq '.data | {id, slug, status}'
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products/<EDGE_PRODUCT_ID>/submit" | jq .data.status   # → needs_review
```

Payloads for the other two (same calls):

```json
{ "slug": "aspire", "name": "Aicountly Aspire",
  "short_description": "Short-term workshops and courses for college students, at colleges or open, run by Aicountly Academy.",
  "description": "<p>Aicountly Aspire runs short-term accounting workshops and courses for college students — at a college, or open to individual students. Aicountly runs registration, attendance and the assessments, and issues an Aicountly Academy certificate to students who meet the course requirements. Fees are shared on registration.</p>",
  "public_url": "https://www.aicountly.com/aspire" }
```

```json
{ "slug": "academy", "name": "Aicountly Academy",
  "short_description": "Industry training for college students, run by Aicountly: Aicountly Edge and Aicountly Aspire.",
  "description": "<p>Aicountly Academy is Aicountly's training arm for college students. It offers Aicountly Edge, a long-term (6–12 month) hands-on accounting programme, and Aicountly Aspire, short-term workshops and courses. Aicountly runs admissions, tests, project evaluation and certificates itself.</p>",
  "public_url": "https://www.aicountly.com/academy" }
```

With Path A, send the same `description` with `PUT /v1/knowledge/products/<ID>` instead.

**Approve** (rows must be `needs_review`; a second reviewer is good practice, not enforced):

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products/<ID>/approve" -d '{"reason":"Academy soft launch 2026-10-25 — copy checked against aicountly.com/academy"}' | jq .data.status
```

**Aliases** (`source` is `brand`):

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products/<ACADEMY_PRODUCT_ID>/aliases" -d '{"alias":"aicountly-academy","source":"brand"}'
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products/<EDGE_PRODUCT_ID>/aliases"    -d '{"alias":"aicountly-edge","source":"brand"}'
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/products/<ASPIRE_PRODUCT_ID>/aliases"  -d '{"alias":"aicountly-aspire","source":"brand"}'
```

Optional brand rule (Knowledge → Brand rules), then submit and approve it the same way:

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/brand-rules" -d '{"rule_type":"preferred_name","rule_text":"Write the brand as Aicountly (mixed case): Aicountly Academy, Aicountly Edge, Aicountly Aspire — never AICOUNTLY.","is_mandatory":true}'
```

---

## 2. Persona `college-student` (Knowledge → Personas)

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/personas" -d '{
  "slug": "college-student",
  "name": "College student (commerce / accounting)",
  "role_title": "Undergraduate or postgraduate student — B.Com, BBA, M.Com, CA/CMA/CS aspirant",
  "description": "<p>A college student in India studying commerce, accounting or finance who wants practical, hands-on accounting experience beyond the classroom. Reached on Instagram, LinkedIn, WhatsApp and through their college. Parents and faculty often influence the decision.</p>",
  "pain_points": [
    "Classroom accounting is theory-heavy; little hands-on practice with real-style books, GST or payroll work",
    "Unsure which skills employers and firms look for in a fresher",
    "Short courses online rarely evaluate their work or give structured feedback",
    "Hard to tell which training programmes are credible",
    "Limited time alongside college classes and exams"
  ],
  "goals": [
    "Build practical accounting skills on real-style tasks and a project",
    "Have their work evaluated and get feedback from practitioners",
    "Earn a certificate from the organisation that trained and tested them",
    "Fit training around college — a short workshop (Aspire) or a 6–12 month programme (Edge)",
    "Talk to admissions before committing"
  ]
}' | jq '.data | {id, slug, status}'
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/personas/<PERSONA_ID>/submit"
curl -s "${H[@]}" -X POST "$REACH/v1/knowledge/personas/<PERSONA_ID>/approve" -d '{"reason":"Academy launch persona"}'
```

---

## 3. Audience segment (Distribution → Segments)

Leads captured from the Academy form (product_interest `edge` / `aspire` / `academy`):

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/distribution/segments" -d '{
  "name": "Academy enquiries — college students",
  "description": "Leads who asked about Aicountly Edge, Aicountly Aspire or Aicountly Academy (aicountly.com/academy form, social, sellers). Persona: college-student.",
  "segment_type": "dynamic",
  "criteria_summary": "product_interest in edge, aspire, academy",
  "rules": [ { "rule_group": 0, "field": "product_interest", "operator": "in", "value": "edge,aspire,academy" } ]
}' | jq '.data | {id, uuid, name}'
curl -s "${H[@]}" -X POST "$REACH/v1/distribution/segments/<SEGMENT_ID>/preview" | jq .data   # 0 before launch is expected
```

Per-course segments, if wanted: the same body with `"value": "edge"` or `"value": "aspire"`
(`operator` `eq`).

---

## 4. Campaigns (Campaigns)

Campaigns have no slug: the launch key is the **name** and the **`utm_campaign`**. Channels are
recorded on the campaign; posting is done by the bot command (step 7).

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/campaigns" -d '{
  "name": "academy-edge-launch",
  "campaign_type": "multi",
  "objective": "Soft launch of Aicountly Edge (6–12 month hands-on accounting programme for college students, run by Aicountly Academy): applications and admissions enquiries through aicountly.com/academy.",
  "target_audience": { "persona": "college-student", "segment_id": <SEGMENT_ID>, "who": "Commerce, accounting and finance college students in India; their faculty and colleges" },
  "products_promoted": ["edge", "academy"],
  "start_date": "2026-10-25",
  "end_date": "2026-12-31",
  "status": "draft",
  "channels": ["linkedin", "instagram", "facebook", "twitter", "whatsapp", "blog", "landing"],
  "utm_source": "reach",
  "utm_medium": "social",
  "utm_campaign": "academy-edge-launch",
  "utm_content": "launch",
  "landing_page_url": "https://www.aicountly.com/academy?utm_source=reach&utm_medium=social&utm_campaign=academy-edge-launch&utm_content=launch",
  "creative_copy": "<p>Aicountly Edge — a 6–12 month hands-on accounting training programme for college students, run by Aicountly Academy. Practical tasks, a project evaluated by Aicountly, tests and an Aicountly Academy certificate for students who meet the requirements. Apply at aicountly.com/academy.</p>"
}' | jq '.data | {id, name, status}'
```

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/campaigns" -d '{
  "name": "academy-aspire-launch",
  "campaign_type": "multi",
  "objective": "Soft launch of Aicountly Aspire (short-term accounting workshops and courses for college students, at colleges or open, run by Aicountly Academy): registrations, college workshop requests and admissions enquiries.",
  "target_audience": { "persona": "college-student", "segment_id": <SEGMENT_ID>, "who": "College students, faculty and training coordinators at commerce colleges in India" },
  "products_promoted": ["aspire", "academy"],
  "start_date": "2026-10-25",
  "end_date": "2026-12-31",
  "status": "draft",
  "channels": ["linkedin", "instagram", "facebook", "twitter", "whatsapp", "blog", "landing"],
  "utm_source": "reach",
  "utm_medium": "social",
  "utm_campaign": "academy-aspire-launch",
  "utm_content": "launch",
  "landing_page_url": "https://www.aicountly.com/academy?utm_source=reach&utm_medium=social&utm_campaign=academy-aspire-launch&utm_content=launch",
  "creative_copy": "<p>Aicountly Aspire — short-term accounting workshops and courses for college students, at your college or open to all, run by Aicountly Academy, with assessments and an Aicountly Academy certificate for students who meet the course requirements. Register at aicountly.com/academy.</p>"
}' | jq '.data | {id, name, status}'
```

Approve both now; set them live on launch morning (no message is sent by either call):

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/campaigns/<EDGE_CAMPAIGN_ID>/approve"
curl -s "${H[@]}" -X POST "$REACH/v1/campaigns/<ASPIRE_CAMPAIGN_ID>/approve"
# 2026-10-25, before step 7:
curl -s "${H[@]}" -X POST "$REACH/v1/campaigns/<EDGE_CAMPAIGN_ID>/status"   -d '{"status":"live"}'
curl -s "${H[@]}" -X POST "$REACH/v1/campaigns/<ASPIRE_CAMPAIGN_ID>/status" -d '{"status":"live"}'
```

**UTM convention** for every link the launch posts: `https://www.aicountly.com/academy?utm_source=<network>&utm_medium=social&utm_campaign=<academy-edge-launch|academy-aspire-launch>&utm_content=launch-v<1|2|3>`
with `<network>` = `linkedin`, `instagram`, `facebook`, `x`, `whatsapp`; blogs use
`utm_source=blog&utm_medium=referral`. (`POST /v1/ads/utm-link` builds the same links if you
prefer.)

---

## 5. Landing page record (Landing pages)

The page itself is built on aicountly.com; Reach keeps a record so leads and reports can point
at it. One record for the shared Academy page:

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/landing-pages" -d '{
  "slug": "academy",
  "title": "Aicountly Academy — Aicountly Edge and Aicountly Aspire",
  "meta": {
    "external_url": "https://www.aicountly.com/academy",
    "hosted_on": "aicountly.com",
    "campaign_ids": { "edge": <EDGE_CAMPAIGN_ID>, "aspire": <ASPIRE_CAMPAIGN_ID> },
    "form": "Talk to admissions → POST /api/v1/public/leads/capture (server-side, product_interest edge|aspire)"
  },
  "body": "<p>Hosted on aicountly.com/academy. Course cards for Aicountly Edge (6–12 months) and Aicountly Aspire (short courses and workshops), Apply buttons and a Talk to admissions form.</p>",
  "status": "draft"
}' | jq '.data | {id, slug, status}'
# when the site page is live:
curl -s "${H[@]}" -X PUT "$REACH/v1/landing-pages/<LANDING_ID>" -d '{"status":"published","published_at":"2026-10-25 09:00:00+05:30"}'
```

(`campaign_id` is left empty because the page serves both campaigns; the lead carries the
campaign.)

---

## 6. `brand.offer` (Settings → AI & Automation → AI vetting → "What we offer new customers")

The code default (used only while the setting is **unset**) now already ends with the Academy
line. If the setting has a stored value, replace it with this exact text (no fee amount):

```bash
curl -s "${H[@]}" -X PUT "$REACH/v1/settings/automation" -d '{
  "brand.offer": "Signing up at aicountly.com is free: the Free plan is free to get started with the launch apps (Smart Books accounting, GST billing & invoicing, Inventory, Notes, Drive) and Manage. There is no free trial. Say \"sign up free\" or \"free to get started\"; never mention trials, usage limits, caps or prices. Aicountly Academy courses (Aicountly Edge, the long-term programme, and Aicountly Aspire, short-term courses and workshops) are open for applications at aicountly.com/academy: say \"apply now\" or \"talk to admissions\"; fees are on the course page or shared by admissions — never state a fee amount, a discount, \"free\" or any placement, job or salary promise."
}' | jq '.data[] | select(.key=="brand.offer") | .value'
```

---

## 7. Launch-day bot commands (Bot → Ask the bot)

Two commands (one per course; each instruction is under the 4,000-character limit). Channels
for the bot are `linkedin`, `instagram`, `facebook`, `twitter` (X) — **WhatsApp channel posts are
manual** (copy in §8). Instagram needs an image or video: put the launch creatives (https URLs)
in `attachments`, otherwise the bot skips Instagram. `idempotency_key` makes a retry safe.
Posting is immediate once Gemini approves — check the copy in §8 first.

```bash
curl -s "${H[@]}" -X POST "$REACH/v1/bot/commands" -d @edge-command.json | jq '.data.command | {id, status}'
curl -s "${H[@]}" -X POST "$REACH/v1/bot/commands" -d @aspire-command.json | jq '.data.command | {id, status}'
```

`edge-command.json`:

```json
{
  "idempotency_key": "academy-edge-launch-2026-10-25",
  "channels": ["linkedin", "facebook", "twitter", "instagram"],
  "attachments": ["https://<creative host>/academy-edge-launch-square.png"],
  "instruction": "Soft launch of Aicountly Edge today (campaign academy-edge-launch). Facts you may use, nothing else: Aicountly Edge is a long-term (6–12 month) hands-on accounting training programme for college students, run by Aicountly Academy. Aicountly runs admissions, the tests and the evaluation of practical tasks and project work, and issues an Aicountly Academy certificate to students who meet the programme requirements. Applications and admissions enquiries: aicountly.com/academy. Rules: write the brand as Aicountly; Edge is a course, not software, never Beta; no fee amounts (fees are shared on admission), no discounts, no 'free'; no placement, job, internship, salary or result promises; never call the certificate a degree or diploma. Submit one social post per network so each link carries its own utm_source, all with utm_medium=social&utm_campaign=academy-edge-launch&utm_content=launch-v1 on https://www.aicountly.com/academy:\n1) LinkedIn (utm_source=linkedin): \"Introducing Aicountly Edge — a 6–12 month, hands-on accounting training programme for college students, run by Aicountly Academy.\\n\\nStudents work on practical accounting tasks and a project, take tests, and have their work evaluated by the Aicountly team. Students who meet the programme requirements earn an Aicountly Academy certificate.\\n\\nApplications are open — apply or talk to admissions:\" + link. Hashtags: #AicountlyAcademy #AicountlyEdge #Accounting #CommerceStudents\n2) Facebook (utm_source=facebook): \"Aicountly Edge is open for applications. It is a 6–12 month, hands-on accounting training programme for college students, run by Aicountly Academy: practical tasks, a project evaluated by Aicountly, tests, and an Aicountly Academy certificate for students who meet the requirements. Apply or talk to admissions:\" + link.\n3) X (utm_source=x, at most 280 characters with link and hashtags): \"Aicountly Edge is open for applications: a 6–12 month hands-on accounting programme for college students, run by Aicountly Academy. Practical tasks, a project, tests and a certificate.\" + link + \" #AicountlyEdge #AicountlyAcademy\"\n4) Instagram (utm_source=instagram; use the attached image; link in bio): \"Learn accounting by doing it. Aicountly Edge is a 6–12 month, hands-on accounting training programme for college students, run by Aicountly Academy. Practical tasks. A project evaluated by Aicountly. Tests. An Aicountly Academy certificate when you meet the requirements. Applications are open — link in bio (aicountly.com/academy).\" Hashtags: #AicountlyAcademy #AicountlyEdge #CommerceStudents #Accounting\nKeep the wording; Gemini may tighten it. Then write and submit ONE blog article for aicountly.com: category accounting, product_slug edge, title along the lines of 'What hands-on accounting training adds to classroom learning', for college students; explain practical accounting skills (bookkeeping, GST basics, reconciliation) in general terms and close with one paragraph on Aicountly Edge using only the facts above and a link to https://www.aicountly.com/academy?utm_source=blog&utm_medium=referral&utm_campaign=academy-edge-launch. If the blog quota is used (429), say so in the report and stop. Report every post id and the blog status."
}
```

`aspire-command.json`:

```json
{
  "idempotency_key": "academy-aspire-launch-2026-10-25",
  "channels": ["linkedin", "facebook", "twitter", "instagram"],
  "attachments": ["https://<creative host>/academy-aspire-launch-square.png"],
  "instruction": "Soft launch of Aicountly Aspire today (campaign academy-aspire-launch). Facts you may use, nothing else: Aicountly Aspire runs short-term accounting workshops and courses for college students — at a college, or open to individual students — run by Aicountly Academy. Aicountly runs registration, attendance and the assessments, and issues an Aicountly Academy certificate to students who meet the course requirements. Colleges can ask for a workshop on campus. Registrations and enquiries: aicountly.com/academy. Rules: write the brand as Aicountly; Aspire is a course, not software, never Beta; no fee amounts, no discounts, no 'free'; no placement, job, internship, salary or result promises; never call the certificate a degree or diploma; no dates or seat counts. Submit one social post per network so each link carries its own utm_source, all with utm_medium=social&utm_campaign=academy-aspire-launch&utm_content=launch-v1 on https://www.aicountly.com/academy:\n1) LinkedIn (utm_source=linkedin): \"Introducing Aicountly Aspire — short-term accounting workshops and courses for college students, run by Aicountly Academy.\\n\\nAspire runs at colleges and as open courses. Students learn hands-on, take the assessments, and those who meet the course requirements earn an Aicountly Academy certificate.\\n\\nFaculty and training coordinators can also ask for an Aspire workshop on campus:\" + link. Hashtags: #AicountlyAcademy #AicountlyAspire #Accounting #CommerceStudents\n2) Facebook (utm_source=facebook): \"Aicountly Aspire is here: short-term, hands-on accounting workshops and courses for college students, run by Aicountly Academy — at your college or open to all, with assessments and an Aicountly Academy certificate for students who meet the course requirements. Register or talk to admissions:\" + link.\n3) X (utm_source=x, at most 280 characters with link and hashtags): \"Aicountly Aspire: short-term, hands-on accounting workshops and courses for college students, run by Aicountly Academy — on campus or open. Assessments and a certificate.\" + link + \" #AicountlyAspire #AicountlyAcademy\"\n4) Instagram (utm_source=instagram; use the attached image; link in bio): \"Short course. Real practice. Aicountly Aspire brings hands-on accounting workshops and courses to college students — on campus or open to all — run by Aicountly Academy, with assessments and an Aicountly Academy certificate when you meet the course requirements. Register — link in bio (aicountly.com/academy).\" Hashtags: #AicountlyAcademy #AicountlyAspire #CommerceStudents #Accounting\nKeep the wording; Gemini may tighten it. Then write and submit ONE blog article for aicountly.com: category accounting, product_slug aspire, title along the lines of 'Short accounting workshops: what a college student can learn in days, not months', in general terms, closing with one paragraph on Aicountly Aspire using only the facts above and a link to https://www.aicountly.com/academy?utm_source=blog&utm_medium=referral&utm_campaign=academy-aspire-launch. If the blog quota is used (429), say so in the report and stop. Report every post id and the blog status."
}
```

Follow-up: `GET $REACH/v1/bot/commands` shows each command's status and report; the posts and
the blog appear under Social and Blog with their vetting decisions. Variants 2 and 3 (§8) are for
the following days — send them as further bot commands (same shape, `utm_content=launch-v2` /
`launch-v3`, new `idempotency_key`).

---

## 8. Suggested post copy (3 variants per network, per course)

All links: the §4 UTM convention, `utm_content=launch-v1|v2|v3`. `<link>` below means that link.
No fee amounts, no placement or job promises, no "Beta".

### Aicountly Edge (`academy-edge-launch`)

**LinkedIn**

1. Introducing Aicountly Edge — a 6–12 month, hands-on accounting training programme for college
   students, run by Aicountly Academy. Students work on practical accounting tasks and a project,
   take tests, and have their work evaluated by the Aicountly team. Students who meet the
   programme requirements earn an Aicountly Academy certificate. Applications are open — apply or
   talk to admissions: <link> #AicountlyAcademy #AicountlyEdge #Accounting #CommerceStudents
2. Classroom accounting and day-to-day accounting work are not the same thing. Aicountly Edge is
   our long-term (6–12 month) training programme for college students that focuses on the
   practical side: real-style tasks, a project evaluated by Aicountly, and tests along the way.
   Aicountly Academy runs it end to end — admissions, evaluation and the certificate. Apply: <link>
3. Faculty and training coordinators: Aicountly Edge gives your students a structured,
   6–12 month hands-on accounting programme, run by Aicountly Academy — practical tasks, a project
   evaluated by Aicountly, tests, and an Aicountly Academy certificate for students who meet the
   requirements. Talk to our admissions team: <link>

**Instagram** (image or reel required; link in bio)

1. Learn accounting by doing it. Aicountly Edge is a 6–12 month, hands-on accounting training
   programme for college students, run by Aicountly Academy. Practical tasks. A project evaluated
   by Aicountly. Tests. An Aicountly Academy certificate when you meet the requirements.
   Applications are open — link in bio. #AicountlyAcademy #AicountlyEdge #CommerceStudents #Accounting
2. Your B.Com, plus months of hands-on practice. Aicountly Edge: practical accounting tasks, a
   project and tests — evaluated by Aicountly Academy. Apply via the link in bio.
   #AicountlyEdge #AicountlyAcademy #BCom #Accounting
3. What does a long-term accounting programme look like? Practical tasks → a project → tests →
   evaluation by Aicountly → an Aicountly Academy certificate for those who meet the requirements.
   That's Aicountly Edge. Link in bio. #AicountlyEdge #AicountlyAcademy #CommerceStudents

**Facebook**

1. Aicountly Edge is open for applications. It is a 6–12 month, hands-on accounting training
   programme for college students, run by Aicountly Academy: practical tasks, a project evaluated
   by Aicountly, tests, and an Aicountly Academy certificate for students who meet the
   requirements. Apply or talk to admissions: <link>
2. Parents and students: Aicountly Edge is a structured, long-term accounting programme where the
   training, the tests and the evaluation are all run by Aicountly Academy. Ask our admissions
   team anything: <link>
3. Practice beats theory. In Aicountly Edge, college students spend 6–12 months on hands-on
   accounting tasks and a project that Aicountly evaluates. Applications are open: <link>

**X** (≤ 280 characters; a link counts as 23)

1. Aicountly Edge is open for applications: a 6–12 month hands-on accounting programme for college
   students, run by Aicountly Academy. Practical tasks, a project, tests and a certificate.
   <link> #AicountlyEdge #AicountlyAcademy
2. College students: get months of hands-on accounting practice, with your work evaluated by
   Aicountly. That's Aicountly Edge, from Aicountly Academy. <link> #AicountlyEdge
3. Aicountly Edge: practical tasks → project → tests → Aicountly Academy certificate for those who
   meet the requirements. 6–12 months. Apply: <link> #AicountlyAcademy

**WhatsApp channel** (post by hand)

1. *Aicountly Edge is open for applications* — a 6–12 month, hands-on accounting training
   programme for college students, run by Aicountly Academy. Practical tasks, a project evaluated
   by Aicountly, tests and a certificate. Apply / talk to admissions: <link>
2. Want hands-on accounting practice beyond the classroom? Aicountly Edge is our long-term
   programme for college students. Details and admissions: <link>
3. Questions about Aicountly Edge? Our admissions team will call you back — leave your details
   here: <link>

### Aicountly Aspire (`academy-aspire-launch`)

**LinkedIn**

1. Introducing Aicountly Aspire — short-term accounting workshops and courses for college
   students, run by Aicountly Academy. Aspire runs at colleges and as open courses. Students learn
   hands-on, take the assessments, and those who meet the course requirements earn an Aicountly
   Academy certificate. Faculty and training coordinators can ask for an Aspire workshop on
   campus: <link> #AicountlyAcademy #AicountlyAspire #Accounting #CommerceStudents
2. Not every student can start with a long programme. Aicountly Aspire offers short, hands-on
   accounting workshops and courses — on campus or open — with assessments run by Aicountly
   Academy. Register or ask about a workshop: <link>
3. Colleges: bring Aicountly Aspire to your campus. A short, hands-on accounting workshop for your
   students, run and assessed by Aicountly Academy. Talk to us: <link>

**Instagram** (image or reel required; link in bio)

1. Short course. Real practice. Aicountly Aspire brings hands-on accounting workshops and courses
   to college students — on campus or open to all — run by Aicountly Academy, with assessments
   and an Aicountly Academy certificate when you meet the course requirements. Register — link in
   bio. #AicountlyAcademy #AicountlyAspire #CommerceStudents #Accounting
2. Got a few days? Learn hands-on accounting with Aicountly Aspire, from Aicountly Academy.
   Link in bio. #AicountlyAspire #AicountlyAcademy #BCom
3. Ask your college for an Aicountly Aspire workshop — or join an open course. Link in bio.
   #AicountlyAspire #AicountlyAcademy #CommerceStudents

**Facebook**

1. Aicountly Aspire is here: short-term, hands-on accounting workshops and courses for college
   students, run by Aicountly Academy — at your college or open to all, with assessments and an
   Aicountly Academy certificate for students who meet the course requirements. Register or talk
   to admissions: <link>
2. A short way to start: Aicountly Aspire workshops give college students hands-on accounting
   practice, assessed by Aicountly Academy. See the courses: <link>
3. Faculty: want an accounting workshop for your students? Aicountly Aspire can run one on
   campus. Ask us: <link>

**X** (≤ 280 characters)

1. Aicountly Aspire: short-term, hands-on accounting workshops and courses for college students,
   run by Aicountly Academy — on campus or open. Assessments and a certificate. <link>
   #AicountlyAspire #AicountlyAcademy
2. Short on time? Aicountly Aspire runs short, hands-on accounting courses for college students.
   From Aicountly Academy. <link> #AicountlyAspire
3. Colleges can now request an Aicountly Aspire accounting workshop on campus. <link>
   #AicountlyAcademy

**WhatsApp channel** (post by hand)

1. *Aicountly Aspire* — short-term, hands-on accounting workshops and courses for college
   students, run by Aicountly Academy. On campus or open to all. Register / enquire: <link>
2. Looking for a short accounting course with real practice? See Aicountly Aspire: <link>
3. Faculty and coordinators: ask us about an Aicountly Aspire workshop at your college: <link>

---

## 9. What the website needs (aicountly.com/academy "Talk to admissions")

The site posts **server-side** (the token is a secret; Reach's CORS allow-list does not include
the site and must not need to):

```
POST https://reach.aicountly.org/api/v1/public/leads/capture
X-Public-Capture-Token: <Reach's PUBLIC_LEAD_CAPTURE_TOKEN>
Content-Type: application/json

{ "name": "…", "mobile": "…", "email": "…", "organization": "<college>",
  "product_interest": "edge" | "aspire",
  "campaign_id": <EDGE_CAMPAIGN_ID or ASPIRE_CAMPAIGN_ID>,
  "source_kind": "website",
  "notes": "…", "ref": "<seller code, optional>",
  "utm_source": "…", "utm_medium": "…", "utm_campaign": "…", "utm_content": "…", "utm_term": "…",
  "landing_url": "https://www.aicountly.com/academy?…" }
→ 201 {"ok":true,"data":{"lead_id":…, "engage_push_status":"pushed|retry_scheduled|…"}}
```

Website env (aicountly-com `includes/academy.php`, branch `claude/academy-alignment`):

| aicountly-com env | Value |
| --- | --- |
| `REACH_PUBLIC_CAPTURE_TOKEN` | the same value as Reach's `PUBLIC_LEAD_CAPTURE_TOKEN` (Reach `.env`; if it is unset, Reach answers 503 and the site falls back to its contact flow — set it) |
| `REACH_PUBLIC_CAPTURE_URL` | optional; default `https://reach.aicountly.org/api/v1/public/leads/capture` |
| `REACH_ACADEMY_EDGE_CAMPAIGN_ID` | `<EDGE_CAMPAIGN_ID>` from step 4 (numeric) |
| `REACH_ACADEMY_ASPIRE_CAMPAIGN_ID` | `<ASPIRE_CAMPAIGN_ID>` from step 4 (numeric) |

Notes:

- `campaign_id` must be a real Reach campaign id (foreign key); a wrong id fails the capture.
- `product_interest` goes to Engage as `interested_product`: Engage must have the codes `edge`,
  `aspire` (and `academy`) — L4.
- `landing_page_id` (step 5) is accepted too, optional.
- The capture is throttled per IP and per token (`throttle:public_capture`, `public_capture_token`).

---

## 10. Checks after launch

- `GET $REACH/v1/campaigns?search=academy` — both `live`.
- `GET $REACH/v1/bot/commands` — both commands `completed` (or `partial` with the reason).
- Leads: Leads screen filtered by campaign; segment preview (`POST …/segments/<id>/preview`).
- Gemini refusals mentioning fees or placement are working as intended: rewrite, don't loosen.
