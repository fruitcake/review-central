---
layout: home

hero:
  name: Review Central
  text: AI code review for private Bitbucket repositories
  tagline: Faster and cheaper than an AI step in Bitbucket Pipelines. Every pull request reviewed against its ticket, by the reviewer and model you choose, on your own server and with your own AI keys, and the result acted on. Open source; GitHub works too.
  image:
    src: /logo.svg
    alt: Review Central
  actions:
    - theme: brand
      text: Try it in ten minutes
      link: /guide/quick-start
    - theme: alt
      text: What is it?
      link: /guide/what-is-review-central
    - theme: alt
      text: View on GitHub
      link: https://github.com/fruitcake/review-central

features:
  - icon: ⚡
    title: Out of the pipeline
    details: No fresh clone and install on build minutes for every push. The installed packages are reused, commits it already reviewed are skipped, and Claude Code resumes its last session on the branch.
  - icon: 🎫
    title: Reviewed against the ticket
    details: The reviewer reads the pull request with its ticket from Jira, Asana or GitHub Issues, so it checks what was asked for, not only what was written.
  - icon: 💬
    title: Acts on the pull request
    details: A summary in the description, a comment per finding, tasks for what blocks the merge, and an approval or a request for changes. The next push marks what was fixed and resolves its threads. Ask it a question with @review.
  - icon: 🧰
    title: Your reviewer, your model
    details: Claude Code, Codex, OpenCodeReview or Pi, with the model and effort you set, and a budget per reviewer. Or only the diff, sent to your AI provider.
  - icon: 🔒
    title: Sandboxed
    details: The reviewer reads the code in a throwaway container under gVisor, or in a Cloudflare sandbox (experimental), holding nothing but the AI key. The diff runner runs nothing at all.
  - icon: 🏢
    title: Many teams, one installation
    details: Each tenant brings its own source control, tickets, AI providers, colours, currency and time zone. One admin panel shows them all, with cost against budget and how often the team agrees with the reviewer.
---

<div class="shots">
  <div class="shot">
    <figure>
      <img src="/screenshots/review.png" alt="A review: the summary, the findings with their severity and category, and what the run cost">
      <figcaption><strong>A review.</strong> The summary, every finding with its place in the code, what is left for a person to check, and what the run cost. Ask the reviewer about it right there.</figcaption>
    </figure>
  </div>
  <div class="shots-two shots" style="margin: 0; padding: 0;">
    <div class="shot">
      <figure>
        <img src="/screenshots/overview.png" alt="A team's overview: what needs attention, reviews per day and findings by category">
        <figcaption><strong>A team's overview.</strong> What needs attention, how reviews ended, and how often findings are accepted.</figcaption>
      </figure>
    </div>
    <div class="shot">
      <figure>
        <img src="/screenshots/platform.png" alt="The platform admin: every tenant with its cost against budget and its health">
        <figcaption><strong>The platform.</strong> Every tenant with its reviews, cost against budget and what blocks it.</figcaption>
      </figure>
    </div>
    <div class="shot">
      <figure>
        <img src="/screenshots/reviews.png" alt="The list of reviews, with tabs for what needs action and what is running">
        <figcaption><strong>Every run.</strong> What needs action, what is running and what did not finish, per pull request.</figcaption>
      </figure>
    </div>
    <div class="shot">
      <figure>
        <img src="/screenshots/setup.png" alt="The setup page: what is in place and what still needs doing">
        <figcaption><strong>Setup that explains itself.</strong> What is in place, and what to do about what is not.</figcaption>
      </figure>
    </div>
  </div>
</div>

<div class="band">
  <h2>Open source, all of it</h2>
  <p>Review Central is MIT licensed. There is no paid tier and no hosted version: you run it, you hold the keys, and you pay your AI provider directly. <a href="/guide/contributing">Contributions are welcome.</a></p>
</div>
