/* ===========================================================================
   THE ASSESSMENT CONFIG FILE
   ---------------------------------------------------------------------------
   This is the file to edit when you want to change what an assessment SAYS.
   You do not need to touch any other file to change wording, questions,
   answers, weights, bands, or the buttons at the end.

   HOW TO EDIT SAFELY
   ------------------
   1. Only change the text between the "quote marks".
   2. Never delete a comma at the end of a line.
   3. Never delete a key name (the word before the colon, like  name:  ).
   4. Weights are whole numbers from 1 to 4. Higher weight = counts for more.
   5. Every question must have exactly five options, worth 0, 1, 2, 3 and 4.
      Zero is the worst case. Four is the best case.
   6. After editing, run:  node tests/systems_audit.test.mjs
      It checks the file is still valid and tells you exactly what broke.

   WHAT EACH FIELD DOES
   --------------------
   key        A short internal id. Do not change these once you are live.
   name       The area name shown on the results page.
   weight     How much this area counts. 4 = most expensive to get wrong.
   question   The question on screen. One question per screen.
   help       Optional grey line under the question. Delete it or set "".
   options    The five answers. points 0 is worst, 4 is best.
   strength   Shown when they scored 3 or 4 here.
   gap        Shown when they scored 0, 1 or 2 here.
   fix        The recommendation for this area.
   day        The single action used in the seven-day plan.

   A RULE THAT MATTERS
   -------------------
   No statistic goes in this file unless there is a source for it. Every claim
   here is about mechanism (what happens when a step is missing), never about
   averages, percentages or money recovered. Keep it that way when you edit.
   =========================================================================== */

export const MAX_POINTS = 4;

/* ---------------------------------------------------------------------------
   THE SIESIE SYSTEMS AUDIT
   Twelve questions about how work moves through a service business, and how
   much of it still routes through the owner.
   --------------------------------------------------------------------------- */

export const SIESIE_AUDIT = {
  id: "siesie-systems-audit",

  /* The header of the app, and what the intro screen says. */
  meta: {
    appName: "Systems Audit",
    appSuite: "Siesie",
    eyebrow: "Siesie · operations diagnostic",
    title: "How much of this business still runs through you?",
    intro:
      "Twelve questions about how work moves from an inquiry to money in the bank, and where it stops along the way. None of them asks about your revenue, your clients or your staff.",
    intro2:
      "You get a score out of 100, the area holding you back most, your top three gaps, and a seven-day plan built from your own answers. It runs entirely in your browser. Nothing is sent anywhere and no email is required to see your result.",
    timeNote: "About four minutes",
    startLabel: "Start the twelve questions",
    resultLabel: "See my result",
    scoreCaption: "out of 100",

    /* The line under the score. This is a read of process, not a valuation. */
    guardTitle: "What this result is not",
    guardBody:
      "This is a read of how work moves, based only on the twelve answers you selected. It is not a valuation, an accounting review, a legal or tax position, and it does not predict revenue. It describes where the work waits.",
  },

  dimensions: [
    {
      key: "ownerAbsence",
      name: "Owner dependence",
      weight: 4,
      question:
        "If you were unreachable for a full week, what would happen to the work you have already sold?",
      help: "Answer for the usual case, not your best week.",
      options: [
        { points: 0, label: "It would stop. Everything sold waits on me to move it." },
        { points: 1, label: "Some of it would continue, and decisions would pile up until I was back." },
        { points: 2, label: "The work would continue, with a few things stalled on my approval." },
        { points: 3, label: "The work would continue and someone else could make most of the calls." },
        { points: 4, label: "The work would continue and nobody outside would notice I was gone." },
      ],
      strength: "Delivery of sold work does not route through one calendar.",
      gap: "When sold work waits on one person, every day that person is unavailable is a day the business owes without producing. This is the gap that decides how much you can safely sell.",
      fix: "Take the three decisions that most often wait for you and write down the rule you already use for each one. A written rule is what lets somebody else decide the way you would have.",
      day: "Write down every decision that came to you this week. Circle the three that did not need you.",
    },
    {
      key: "intake",
      name: "Inquiry handling",
      weight: 3,
      question: "A new inquiry arrives at nine on a Saturday night. What happens next?",
      help: "Answer for the usual case, not your best week.",
      options: [
        { points: 0, label: "Nothing until I see it, whenever that is." },
        { points: 1, label: "I see it when I next check my phone and reply when I can." },
        { points: 2, label: "It gets a reply within a day, from me." },
        { points: 3, label: "Something acknowledges it straight away and I follow up." },
        { points: 4, label: "Something acknowledges it, asks the qualifying questions, and I pick up a prepared inquiry." },
      ],
      strength: "An inquiry gets an answer at the hour it arrives, not the hour you are free.",
      gap: "An inquiry that waits until you are free is competing against whoever replied first. Reply speed does the selling before your actual work gets a chance to.",
      fix: "Write the four questions you always end up asking a new inquiry, and put them in front of the inquiry instead of after it.",
      day: "Find your last ten inquiries and write down how many hours passed before each one got a reply.",
    },
    {
      key: "quoting",
      name: "Quoting and pricing",
      weight: 3,
      question: "How does a price get decided?",
      help: "",
      options: [
        { points: 0, label: "I work it out fresh each time, in my head." },
        { points: 1, label: "I have a rough sense of it and adjust job by job." },
        { points: 2, label: "I look at what similar past jobs cost and price from there." },
        { points: 3, label: "There is a written price structure and I follow it." },
        { points: 4, label: "There is a written structure, and someone else could quote a standard job without me." },
      ],
      strength: "Pricing is a calculation you can repeat, so the same job costs the same twice.",
      gap: "A price held in your head cannot be delegated, cannot be checked and drifts quietly. Two similar jobs quoted a month apart come out different, and neither of you can say why.",
      fix: "Write down what you charged on your last ten jobs and what drove each number. The structure is usually already there. It has just never been written down.",
      day: "Write the price structure for your three most common jobs, including what makes one cost more.",
    },
    {
      key: "scheduling",
      name: "Scheduling",
      weight: 3,
      question: "How does work get onto the calendar?",
      help: "",
      options: [
        { points: 0, label: "By message, and I hold the schedule in my head." },
        { points: 1, label: "I keep a calendar, and I am the only one who can book into it." },
        { points: 2, label: "Bookings come through me and land in a calendar others can see." },
        { points: 3, label: "Clients can see availability and request a time, and I confirm it." },
        { points: 4, label: "Clients book real availability directly, and the confirmation and reminder go out on their own." },
      ],
      strength: "A booking no longer costs a conversation.",
      gap: "Every booking that takes a back and forth costs the same attention as a small job. Ten of those in a week is a working day you did not bill for.",
      fix: "Publish the hours you actually want to be booked and let people take them. The negotiation is the part that can go, not the confirmation.",
      day: "Count how many messages it took to book each of your last five jobs.",
    },
    {
      key: "handover",
      name: "Handover into delivery",
      weight: 3,
      question:
        "When a job is sold, how does everything needed to do the work reach whoever does it?",
      help: "If you do the work yourself, answer for what reaches you on the day.",
      options: [
        { points: 0, label: "It lives with me and I explain it as we go." },
        { points: 1, label: "I forward the messages and repeat the details out loud." },
        { points: 2, label: "I write it up each time, in whatever form suits that job." },
        { points: 3, label: "There is a standard place and a standard format for job details." },
        { points: 4, label: "The details arrive complete, in one place, in the same shape every time, without me assembling them." },
      ],
      strength: "Sold work reaches delivery complete, so the work starts on time.",
      gap: "When a handover is a retelling, the details that go missing are the ones nobody knew to ask about. The cost lands later as a callback, not at the handover.",
      fix: "Write the list of what delivery has to know before starting, and stop handing over a job that is missing any of it.",
      day: "Ask whoever does the work what they wish they had known before starting the last three jobs.",
    },
    {
      key: "procedure",
      name: "Written procedure",
      weight: 3,
      question: "How much of the way your work gets done is written down?",
      help: "",
      options: [
        { points: 0, label: "None of it. It is just how I do it." },
        { points: 1, label: "A few notes, mostly written for me." },
        { points: 2, label: "The main steps exist somewhere, and they are out of date." },
        { points: 3, label: "The core jobs have current written steps." },
        { points: 4, label: "Current written steps, and people follow them instead of asking me." },
      ],
      strength: "The method exists outside the heads of the people who happen to know it.",
      gap: "An unwritten process cannot be taught, cannot be improved on purpose, and leaves with whoever was holding it. Every question that reaches you is the running cost of it staying unwritten.",
      fix: "Record yourself doing the job once and have the steps typed up from the recording. Writing procedure from memory is what makes it take months and never finish.",
      day: "Pick your most repeated job. Write the steps down while you do it, not afterwards.",
    },
    {
      key: "invoicing",
      name: "Invoicing and collection",
      weight: 4,
      question: "How does an invoice go out, and how does an unpaid one get chased?",
      help: "Both halves count. Sending is the easy half.",
      options: [
        { points: 0, label: "When I remember, and chasing happens when I notice." },
        { points: 1, label: "I send them in a batch when I get to it, and I chase the big ones." },
        { points: 2, label: "Invoices go out reliably. Chasing depends on the week." },
        { points: 3, label: "Invoices go out on completion and I review what is unpaid regularly." },
        { points: 4, label: "Invoices go out on completion, and unpaid ones get chased on a schedule I do not have to start." },
      ],
      strength: "Money already earned gets asked for on a schedule rather than when it is noticed.",
      gap: "An invoice sent late is paid late, and an unpaid invoice nobody is chasing slowly turns into a discount you never agreed to. This is the quickest gap on the list to close.",
      fix: "Make sending the invoice part of finishing the job rather than a separate task for later, and fix one day a week for looking at what is still unpaid.",
      day: "List everything unpaid right now with the date it went out, and sort by oldest.",
    },
    {
      key: "followup",
      name: "Follow-up after the work",
      weight: 2,
      question: "After a job is finished and paid, what happens?",
      help: "",
      options: [
        { points: 0, label: "Nothing. I hear from them if they need me again." },
        { points: 1, label: "I reach out when I think of it." },
        { points: 2, label: "I follow up with the clients I most enjoyed working with." },
        { points: 3, label: "There is a follow-up I do, and I am mostly consistent about it." },
        { points: 4, label: "Follow-up goes out on its own, and repeat work comes back without me chasing it." },
      ],
      strength: "Past clients are worked as a source of new work rather than filed as history.",
      gap: "A finished client is the cheapest work available to you and the easiest to forget. Silence after a good job reads, from their side, as a business that has moved on.",
      fix: "Pick one moment after a job ends, decide what gets sent at that moment, and send the same thing every time.",
      day: "Write to five past clients from the last year. One line each, nothing to sell.",
    },
    {
      key: "records",
      name: "Where the information lives",
      weight: 2,
      question:
        "If you needed everything about a client from two years ago, where would you look?",
      help: "",
      options: [
        { points: 0, label: "Several places, and I might not find all of it." },
        { points: 1, label: "My phone, my email, and probably a notebook." },
        { points: 2, label: "Mostly in one place, with some of it elsewhere." },
        { points: 3, label: "One system holds the client history." },
        { points: 4, label: "One system, and anyone who needs it can find it without asking me." },
      ],
      strength: "Client history can be retrieved by more than one person.",
      gap: "Information spread across a phone, an inbox and a notebook cannot be handed to anybody. It is the reason bringing in help does not reduce the workload for the first few months.",
      fix: "Choose the one place client information is going to live, then move each client across as they come up rather than attempting it all at once.",
      day: "Take your three most active clients and put everything about them in one place.",
    },
    {
      key: "tools",
      name: "Re-entered information",
      weight: 2,
      question: "How often does the same piece of information get typed in twice?",
      help: "",
      options: [
        { points: 0, label: "Constantly. Almost everything gets re-typed somewhere." },
        { points: 1, label: "Often, between messages, the calendar and the invoice." },
        { points: 2, label: "A few times per job." },
        { points: 3, label: "Rarely. Most details carry through on their own." },
        { points: 4, label: "It does not happen. Information is entered once and shows up where it is needed." },
      ],
      strength: "Information is captured once and then travels.",
      gap: "Re-typing is where the wrong address and the wrong date come from, and it is invisible work: it never appears on anyone's list and it never finishes.",
      fix: "Find the detail you type most often, and connect the two places it lives so it only ever gets typed at the first one.",
      day: "For one working day, put a mark on paper every time you type the same detail twice.",
    },
    {
      key: "numbers",
      name: "Visibility of the numbers",
      weight: 3,
      question:
        "Right now, without opening anything, what is sitting in your pipeline unclosed?",
      help: "This is about how fast the answer is available, not how big the number is.",
      options: [
        { points: 0, label: "I would have to work it out, and I am not certain I could." },
        { points: 1, label: "I could get roughly close from memory." },
        { points: 2, label: "I could work it out in an hour from my records." },
        { points: 3, label: "I could pull it in a few minutes." },
        { points: 4, label: "It is already visible, next to what is unpaid and what is booked." },
      ],
      strength: "The state of the business is already there when you look for it.",
      gap: "A number you have to reconstruct is a number you only check once something has already gone wrong. Until then the decisions get made on the feeling instead.",
      fix: "Build one short weekly view: what is quoted and unclosed, what is booked, what is unpaid. Keep it short enough that it still gets updated in a busy week.",
      day: "Write those three numbers down once, and note how long it took you to find them.",
    },
    {
      key: "onboarding",
      name: "Bringing someone in",
      weight: 2,
      question: "If you brought someone in on Monday, how long until they were useful?",
      help: "",
      options: [
        { points: 0, label: "Months, and I would be slower the entire time." },
        { points: 1, label: "Weeks of me explaining things as we go." },
        { points: 2, label: "A couple of weeks, with a lot of questions." },
        { points: 3, label: "About a week, using what is already written down." },
        { points: 4, label: "Days. There is a path to follow and they can follow it." },
      ],
      strength: "Capacity can be added without spending your own capacity to add it.",
      gap: "When onboarding runs entirely through you, a hire costs more than it returns for the first months. That is the mechanism that keeps a business the size of one person.",
      fix: "Write the first week for a new person: what to read, what to watch, what to do, in that order. Two pages is enough to start with.",
      day: "Write the first three things a new person would need to know. That is the beginning of the path.",
    },
  ],

  /* The four overall bands. Edit the wording freely. Keep the ranges touching
     so every score from 0 to 100 lands in exactly one band. */
  bands: [
    {
      min: 0,
      max: 39,
      label: "The business runs through you",
      blurb:
        "Almost every path through the work passes across your desk. That is a normal place for a business to be at this stage, and it means the first few changes are the ones that buy back the most time.",
      next: "Start with the Operations Map. A build before the map would be guessing at which handoff to remove first.",
    },
    {
      min: 40,
      max: 59,
      label: "Held together by memory",
      blurb:
        "The work gets done, and too much of how it gets done lives in your head rather than anywhere someone else can reach. The usual symptom is that the answer to where is this job depends on who you ask.",
      next: "Start with the Operations Map. Most of what is missing here is written form, and the map is what produces it.",
    },
    {
      min: 60,
      max: 79,
      label: "Working, with named gaps",
      blurb:
        "Most of the structure holds. The gaps that are left are specific ones, and specific gaps close one at a time. Nothing here calls for rebuilding what you already have.",
      next: "The Operations Map, scoped to the two or three areas below rather than the whole business.",
    },
    {
      min: 80,
      max: 100,
      label: "Runs without you in the room",
      blurb:
        "The fundamentals are in place and the business does not stop when you do. What is left is refinement, and the highest-value work is usually removing the last decisions that still route to you.",
      next: "A build conversation may fit directly. Bring the two areas below and we will look at whether they justify one.",
    },
  ],

  /* The per-area labels shown on each meter in the results. */
  areaBands: [
    { min: 0, max: 25, label: "Runs through you" },
    { min: 26, max: 50, label: "Needs attention" },
    { min: 51, max: 75, label: "Working" },
    { min: 76, max: 100, label: "Holds on its own" },
  ],

  /* Shown when somebody scores well enough that no area lands in the gap
     range, so the results page always has something useful to say. */
  noGapsNote:
    "No area landed in the gap range. At this level the useful work is usually the last decision that still routes to you rather than any single process.",
  noStrengthNote:
    "No area reached the strength threshold on these answers. That makes the order below matter more than usual: work from the top down rather than picking the one you like.",

  /* The results page copy and the buttons at the end. */
  result: {
    strongestLabel: "Strongest area",
    weakestLabel: "Weakest area",
    pairTitle: "Where you are strongest, and where you are weakest",
    gapsTitle: "Your top three gaps, in the order they cost you most",
    priorityTitle: "Where to start",
    planTitle: "Your seven-day plan",
    planNote:
      "Each step is scoped to fit around the work you already have. None of them needs new software, and none of them needs anything you do not already hold.",
    rowsTitle: "Every area, scored",
    recsTitle: "What to change, area by area",
    nextTitle: "What this points to",
  },

  /* The call to action under the result. Prices are the live ones on the site.
     If a price changes, change it here and on /operations-map together. */
  cta: {
    eyebrow: "The Operations Map · $2,500 · two weeks",
    title: "See the whole business before you change any of it.",
    body:
      "The Operations Map takes two weeks. Every handoff drawn, every task that still waits on you named and costed, and the build that removes them, in the order it should happen. It is $2,500, credited in full against a Siesie build.",
    primary: { label: "Start with the map", href: "/siesie-application" },
    secondary: [
      { label: "Read what the map produces", href: "/operations-map" },
      { label: "Book 15 minutes first", href: "/book-call" },
    ],
  },

  /* The optional lead capture that appears under the result. The result is
     shown first and in full. This form sends a copy, it does not unlock one. */
  lead: {
    endpoint: "/audit-lead.php",
    title: "Want this emailed to you?",
    body:
      "Your result is already on screen and you can print or download it without giving anything. If you would rather have it in your inbox, put your name and email in and it will be sent to you as one page.",
    consent:
      "Sending this shares your score and your answers with Nana Frimpongmaa. No other information is collected and nothing is passed to anyone else.",
    button: "Email me this result",
    sending: "Sending",
    done: "Sent. Check your inbox, and your spam folder if it is not there in a few minutes.",
    partial:
      "Your result reached Nana Frimpongmaa, and the emailed copy did not go out. Use Download below to keep it and she will follow up.",
    failed:
      "That did not send. Use Save or print below instead, and nothing is lost.",
    nameLabel: "Your name",
    emailLabel: "Your email",
    businessLabel: "Business name",
    businessHint: "Optional",
  },
};

/* ---------------------------------------------------------------------------
   THE DENIAL HEALTH SCORE.

   The twelve questions for that assessment live in their own older file,
   /assets/denial-health-score.js, and are tested separately. It was built
   before this engine and it works, so it was not rebuilt. What it did not have
   was a way to leave with the result, and that is all that is configured here:
   the summary heading, the guard rail, and the lead capture wording.

   Both assessments post to the same endpoint in the same shape.
   --------------------------------------------------------------------------- */

export const DHS_CONFIG = {
  id: "denial-health-score",

  meta: {
    appName: "Denial Health Score",
    scoreCaption: "out of 100",
    guardTitle: "What this result is not",
    guardBody:
      "This is a read of process, based only on the twelve answers you selected. It is not a compliance, privacy, security, legal or coding determination, it does not evaluate any individual claim, and it does not predict what any denial would recover.",
  },

  noStrengthNote:
    "No area reached the strength threshold on these answers. That makes the order below matter more than usual: start at the top and work down.",

  lead: {
    endpoint: "/audit-lead.php",
    title: "Want this emailed to you?",
    body:
      "Your result is already on screen and you can print or download it without giving anything. If you would rather have it in your inbox, put your name and email in and it will be sent to you as one page.",
    consent:
      "Sending this shares your score and your answers with Nana Frimpongmaa. It carries no patient information, because none was asked for at any point.",
    button: "Email me this result",
    sending: "Sending",
    done: "Sent. Check your inbox, and your spam folder if it is not there in a few minutes.",
    partial:
      "Your result reached Nana Frimpongmaa, and the emailed copy did not go out. Use Download below to keep it and she will follow up.",
    failed: "That did not send. Use Save or print below instead, and nothing is lost.",
    nameLabel: "Your name",
    emailLabel: "Your work email",
    businessLabel: "Organization",
    businessHint: "Optional",
  },
};
