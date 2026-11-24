# Setting up a tenant

In the admin panel, at `/admin`:

1. Add the tenant, the users who may open it, and which of them are its [admins](#admins-and-members). A new user is mailed an invitation to choose a password, so the installation needs working mail (`MAIL_*` in `.env`) and a running queue. From the list of users the invitation can be sent again, and a password reset mailed.

In the tenant's panel, at `/app/<tenant>`. Every connection form has **How to get this** next to the service you pick: a link to the page where its key or token is made, the steps there, and the scopes it needs with why.

2. **Source control**: add the host.
   - **GitHub**: a fine-grained personal access token for the repositories, with read access to Contents and Metadata, read and write access to Pull requests, Issues and Commit statuses, and Webhooks to have the webhook added for you. The review comments, approves and requests changes as the account that owns the token, so give it an account of its own, a machine user: GitHub does not let an account approve its own pull request. The owner is the user or organisation from the address. Without a token, a public repository is only read, and its pull requests can be tried.
   - **GitHub App**, where the installation has [one set up](#github-as-an-app): install the app on the organisation, add the connection with the organisation as its owner, and use **Connect** in the list. No token, and no webhook to add.
   - **Bitbucket Cloud**: a workspace access token that may write pull requests. Under **Reviews**, a Bitbucket or GitHub connection can keep its reviews in Review Central and post nothing on the host: no comment, status, task or approval, whatever its token may do, so a token that may only read is enough. Each review is then on its own page only, and a question asked on the pull request is answered there.
   - **Git**: clones any other host over HTTPS, and posts nothing.

   **AI providers**: add a key. "Test" checks the credentials. Which reviewer runs on it, and at which model and effort, is set under **Review defaults**.

   | Provider | Used by |
   |---|---|
   | Anthropic | Claude Code, OpenCodeReview, the review from the diff, questions on a pull request |
   | OpenAI | Codex, OpenCodeReview, the review from the diff |
   | Gemini, Mistral, Groq, DeepSeek, xAI, OpenRouter | The review from the diff, with that provider's own model; Pi in a trial |

   Anthropic and OpenAI take a base URL too, for a gateway that speaks their API.
3. **Tickets**, for ticket context: the tracker (Jira, Asana or GitHub issues) with its credentials, and which references in a pull request title name a ticket there: `PREFIX-123` with a list of prefixes, or `#1234`. Jira and Asana take a token, or a sign-in there when the installation has an OAuth app for them (see [below](#signing-in-to-asana-and-jira)).
4. **Repositories**: choose the connection and search for the repository by name. The workspace is the connection's, and the stack is read from the repository's `composer.json` when it is added; editing the repository changes the stack, its name here and the instructions it reads. Editing also moves it to another connection to the same host, such as from GitHub with a token to the [GitHub App](#github-as-an-app), with its history. **Remove** takes it off the list and stops its reviews, but keeps its reviews, questions and pull requests; adding it again, on this connection or another to the same host, brings it back with them. A removed source control connection likewise keeps the history of its repositories, and loses its credentials and webhook.
5. On the source control connection, **Webhook** shows the address and secret. The webhook is what starts a review: for a push to a pull request, a `/review` comment and a draft marked ready. **Add it for me** adds it for you; the screen says how to add it by hand. Nothing is needed in the repository itself.
   - **GitHub**: on the organisation, which covers every repository it has and gets, when the token may manage the organisation's webhooks; otherwise on each repository of the connection, so add the webhook again after adding a repository. By hand: the repository's or organisation's **Settings → Webhooks**, with this URL as the payload URL, content type `application/json`, the secret, and the events **Pull requests**, **Issue comments**, **Pull request review comments** and **Pull request reviews**.
   - **Bitbucket**: on every repository of the workspace, which needs the webhook scope on the token: Bitbucket has no screen for a workspace's webhooks, only an API. Without that scope, add it to each repository under **Repository settings → Webhooks**, with the pull request events Created, Updated, Approved, Approval removed, Changes request created, Changes request removed, Merged, Declined and Comment created.

## Admins and members

Everyone in a tenant is an admin or a member. An admin manages its connections and their keys, its apps, repositories, review settings, instructions and members; a member reads the reviews and pull requests, answers and rates findings, asks the reviewer and starts a review, and sees no key or setting. An administrator of the platform is an admin of every tenant.

The platform's administrator picks a tenant's admins on the tenant, under **Its admins**. A tenant's admins manage the rest under **Members** in its panel: **Invite** adds someone by address, with a role, and mails a way in to someone who has no account yet; **Change role** and **Remove** do what they say. A tenant keeps at least one admin of its own. Someone who joins by signing in with Google joins as a member.

## GitHub: the account behind the token

On GitHub the review acts as the account that owns the token. That has a few consequences:

- **The review shows under that account's name.** With your own token, its comments and reviews are yours. Give it an account of its own, a *machine user*: an ordinary GitHub account for the bot, such as `acme-review`, with a mailbox of its own and two-factor authentication. Invite it to the organisation with **Write** access to the repositories, and make the token on that account, with the organisation as its resource owner. If the organisation requires approval of fine-grained tokens, an owner approves it under the organisation's **Settings → Personal access tokens**. GitHub allows one machine user per person.
- **It cannot approve or request changes on its own pull requests.** GitHub refuses that for any account, so on a pull request opened by the token's owner the review posts its comments and description, and its verdict is refused; the run's details say so. Another reason for an account of its own.
- **The commit status always lands.** "AI Review" is red when a finding holds the merge, also where the verdict was refused. To have it hold the merge, make it a required status check in the branch's protection rules.
- **`@review` is someone's GitHub user name.** A question that starts with `@review` mentions that account, which on a public repository notifies a stranger. On a public repository, ask in a reply under one of the review's own comments instead: a reply there that names nobody is taken as a question for the reviewer when it reads as one.
- **No tasks and no check run.** GitHub has no tasks on a pull request, and only a GitHub App may create a check run with annotations: see [GitHub as an app](#github-as-an-app). The findings are comments on their lines, and the request for changes is what holds the merge.

A token expires: note the date, and replace it on the connection before then. **Test** on the connection says whether it still works.

A tenant's own colours are set on the tenant: see [Appearance](./appearance).

## GitHub as an app

Instead of a token per connection, the installation can be registered as a GitHub App, which tenants install on their organisation. The review then comments and approves as the app, `<name>[bot]`, which needs no machine user and no token that expires. Its state on a pull request is a check, *AI Review*, with the report as its summary and the findings as annotations on their lines under **Files changed**. While the review runs, the check shows how long it has taken and the last lines of its log, every 20 seconds, as the review's page does. On a public repository it shows only the review's steps: anyone can read the check there, and the reviewer's own words are words a pull request or a comment on it can put in its mouth. A pull request that waits for approval (see [Pull requests](./reviews#pull-requests)) gets a check that needs action, with an **Approve and review** button: pressed by one of the tenant's trusted accounts, it starts the review; anyone else's press does nothing.

An app cannot be added as a reviewer, and `@<app name>` notifies nobody. `/review` and `@review` work as with a token: the webhook brings every comment.

To register it, as the installation's administrator: on GitHub, under the organisation's (or your own) **Settings → Developer settings → GitHub Apps → New GitHub App**:

- **Callback URLs**: `<APP_URL>/connections/oauth/callback`, for connecting an installation, and `<APP_URL>/connections/github/callback`, for people who [sign in with GitHub or reply as themselves](#replying-and-signing-in-with-github). Leave *Allow wildcard matching* off on both: Review Central sends exactly these, and a wildcard lets GitHub send a sign-in's code to other addresses as well. Leave *Expire user authorization tokens* on, and *Request user authorization (OAuth) during installation* off.
- **Webhook URL**: `<APP_URL>/api/webhooks/github-app`, with a secret of your own.
- **Repository permissions**: Contents and Metadata read; Pull requests, Issues, Commit statuses and Checks read and write. Contents read and write is optional: GitHub asks it of an app that resolves a review thread, as the review does for a fixed finding, and it would also let the app push. Without it the thread stays open, and the finding says it is done on its first line (`F1 · HIGH · ✅ Fixed`, as in the description) and in the reply under it. **Organization permissions**: Members read, to check who owns an organisation. **Account permissions**: Email addresses read, for signing in with GitHub; GitHub asks it of each person when they sign in, not of the organisations that install the app.
- **Events**: Pull request, Issue comment, Pull request review comment, Pull request review, Check run.
- **Where can this GitHub App be installed**: *Any account*, for tenants outside your own organisation. That needs no verification by GitHub; it asks for one only for a paid listing in the Marketplace.

Then generate a private key and a client secret, and add it under **Admin → Apps**: its ID, the name in its address, the client ID and secret, the webhook secret and the contents of the private key. `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY` (the PEM file's path, or its contents) and `GITHUB_APP_WEBHOOK_SECRET` in `.env` do the same, and go first. Until all but the webhook secret are set, *GitHub App* is not offered as a connection, and the setup page says the app does not work while the webhook secret is missing.

**Connect** signs the person in at GitHub once, to check that they own the connection's owner: an owner of the organisation, or the user account itself. A member who only has access to some of its repositories cannot connect it. Their own token is revoked straight after. An installation belongs to one connection.

## Signing in with Google

With a Google OAuth client added under **Admin → Apps**, or `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`, the sign-in page has a **Sign in with Google** button. Make the client in the Google Cloud console, of the type "Web application", with `<APP_URL>/sign-in/google/callback` as its redirect address. It is for the whole installation: it comes before any tenant is known, and who joins which tenant goes by the tenant's sign-in domains.

A user whose address is already known signs in as that user, when Google holds that address: a Gmail address, or one of a Google Workspace organisation at its own domain. Someone new joins a tenant only when the tenant lists their organisation's domain under **Sign-in domains** in the admin panel, and Google says the account is that organisation's; a personal account under the same domain does not count, and a Gmail address never joins one.

## Replying on Bitbucket as yourself

Replies to findings that an AI assistant posts for you are written by the tenant's connection, with your name above them. With a Bitbucket OAuth consumer added under **Admin → Apps**, or `BITBUCKET_CLIENT_ID` and `BITBUCKET_CLIENT_SECRET` in `.env`, each person can connect their own Bitbucket account under **Your Bitbucket account** on their profile, and their replies are then written as them.

Make the consumer in any Bitbucket workspace you administer, under **Settings → OAuth consumers**, with `<APP_URL>/connections/bitbucket/callback` as its callback URL and the permissions **Account: Email**, **Account: Read** and **Pull requests: Read**, which includes commenting: Bitbucket gives every token the consumer's permissions, whatever is asked for, signing in included. Add **Pull requests: Write** only to let an assistant open and edit people's own pull requests as them (see [Opening pull requests as yourself](#opening-pull-requests-as-yourself)): it also lets the token merge, and Bitbucket ticks Repositories: Write with it, which lets it push. People of other workspaces connect through it too. The account is kept for that person only and used for nothing but their replies, though Bitbucket lets its token comment on every pull request they can. A connected account also tells which pull requests are theirs under **With me**; the profile shows it as *Bitbucket: nickname*. When Bitbucket refuses a reply as them, as on a repository their account cannot reach, the connection posts it as before.

The tenant's connection itself stays a token of its own: the review comments, approves and adds webhooks as it, whoever connects their account.

### Signing in with Bitbucket

With the same consumer, the sign-in page has a **Sign in with Bitbucket** button. Bitbucket names an account's address but does not deliver its mail, so its word alone is not enough: someone who had an address at a former employer may still have a Bitbucket account under it. A Bitbucket account signs in by itself once it is linked: by connecting it on the profile, or by signing in with it once and typing the code that is then mailed to one of the account's confirmed addresses: the first that has an account here, else the first whose domain a tenant lets in, the primary address before the others. The code works for 15 minutes, in the browser that signed in. Someone without an account here gets one that way only when a tenant lists their address's domain under **Sign-in domains**, as with Google; the code shows the address is theirs.

## Replying and signing in with GitHub

With the GitHub App set up, each person can connect their own GitHub account under **Your GitHub account** on their profile, and the sign-in page has a **Sign in with GitHub** button. Both go through the app, and come back on its callback `<APP_URL>/connections/github/callback`; nothing else is to be registered.

Connected, a reply to a finding that an AI assistant posts for you is written as you, with the app's badge next to your name, instead of by the app with your name above it. GitHub gives the app's token for a person the app's permissions and no more than theirs, and only on the repositories the app is installed on: a repository whose connection is a token of its own gets the reply from that connection, signed with your name, as before. The token lasts eight hours and is renewed by itself; **Disconnect** also has GitHub revoke it. A connected account also tells which pull requests are yours under **With me**.

Signing in works as with Bitbucket: GitHub names an account's verified addresses, but "verified" was true once and an address may belong to someone else by now, so the first sign-in with an account that is not linked yet mails a code to one of them, chosen as with Bitbucket. A connected account signs in by itself. Someone without an account here gets one that way only when a tenant lists their address's domain under **Sign-in domains**. Without the app's *Email addresses* permission GitHub names no address, and signing in with an account that is not linked yet is refused.

## Opening pull requests as yourself

A coding agent connected to `/mcp/agent` can open the pull request of a pushed branch with `create-pull-request`, and rewrite the title and description of one later with `update-pull-request`, both under the user's own name. It goes through the account the user connected on their profile:

- **Bitbucket**: the consumer needs **Pull requests: Write**, and the user connects their account after it was added. The pull request gets the repository's default reviewers, as one opened on Bitbucket's site does.
- **GitHub**: through the GitHub App, which needs nothing more, on the repositories it is installed on and reviews through. It shows the user's name with the app's badge.

`update-pull-request` changes only a pull request whose author is the user's connected account; someone else's is refused. The review's own section in the description stays as it is. A repository whose connection is set to write nothing gets neither.

## Signing in to Asana and Jira

A ticket connection can sign in at Asana or Jira instead of taking a pasted token, when the installation is registered as an OAuth app there, with `<APP_URL>/connections/oauth/callback` as its redirect address. Add the app under **Admin → Apps**, or set it in `.env`. Each person who connects says yes with their own account, and the tokens are kept per connection, with the app they came through:

- **Asana**: a new app in the [developer console](https://app.asana.com/0/my-apps) (`ASANA_CLIENT_ID`, `ASANA_CLIENT_SECRET`). The app needs the read scopes in `ASANA_OAUTH_SCOPE`, `attachments:read` included for the files on a task (an app connected before it was asked for reads tasks without their files until it is connected again); leave that empty for an app with full permissions. Under **Manage distribution**, let it be used in any workspace, for tenants outside your own organisation.
- **Jira**: an OAuth 2.0 (3LO) app in the [Atlassian developer console](https://developer.atlassian.com/console/myapps/), with read access to Jira issues (`ATLASSIAN_CLIENT_ID`, `ATLASSIAN_CLIENT_SECRET`). Under **Distribution**, set it to sharing, so people of other organisations can authorize it.

Without an app, a connection takes a token only, and its form says why.

## Apps, and which tenant has which

**Admin → Apps** lists the apps a person signs in at a service through. **Add app** picks its type:

- **Google**, to sign in with: there is one, for everyone, before any tenant is known. Who joins which tenant goes by the tenant's sign-in domains.
- **GitHub App**: one, for now. Signing in with GitHub and replying as yourself go through it too.
- **Asana** and **Atlassian**: as many as you like, for instance one per agency or customer.

An app other than Google is for the tenants picked on it, or for every tenant; a new one is for none until it is given to some. A tenant can also add its own Asana or Atlassian app under **Apps** in its panel, for itself alone. A connection that can sign in through more than one asks which when **Connect** is pressed, and keeps renewing its access through that one. Its form says why it cannot sign in where no app is there for its team. What `.env` sets is an app for every tenant too, listed first; for Google and the GitHub App it goes before the one in the list.
