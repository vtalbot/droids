# Run droids on your computer

Droids don't run on the server. The server keeps the queue, and a **worker** on your own computer picks up each task, runs Claude Code or Codex in a fresh clone of the repository, and pushes a branch. The server then opens the pull request and moves the task to Droid reviewing.

The worker is the same Laravel app, started with `php artisan droids:work`. It uses the Claude and Codex subscriptions you are signed in to on that computer, so no API key ever has to live on the server.

## 1. Server settings

Add this to the server's `.env`, then run `php artisan optimize`:

```dotenv
# The schedule grid on the Subscriptions page is read in this time zone.
DROIDS_TIMEZONE=America/Toronto
```

## 2. What the computer needs

- PHP 8.3 or later, Composer, git, and a checkout of this repository (`composer install` is enough: the worker doesn't need the front end built).
- [Claude Code](https://docs.claude.com/en/docs/claude-code) (`claude`) and/or [Codex CLI](https://github.com/openai/codex) (`codex`) on your `PATH`.

## 3. Create a worker token

In the app, open **Settings > Workers**, name the computer, and press **Create token**. Copy the two lines it shows into the `.env` of your checkout on that computer:

```dotenv
DROIDS_SERVER=https://droids.example.com
DROIDS_WORKER_TOKEN=dw_...
```

The token is shown once. If you lose it, delete the worker and create a new one.

## 4. Sign in to each subscription

Every subscription on the Subscriptions page has a slug, such as `claude-max` or `codex-plus`. The worker gives each one its own login folder under `~/.droids/logins/<slug>`, so two Claude accounts never share a session.

Sign in once per subscription:

```bash
# A Claude subscription
mkdir -p ~/.droids/logins/claude-max
CLAUDE_CONFIG_DIR=~/.droids/logins/claude-max claude
# then type /login, finish the sign-in, and /exit

# A Codex subscription
mkdir -p ~/.droids/logins/codex-plus
CODEX_HOME=~/.droids/logins/codex-plus codex login
```

If you would rather reuse the login you already use every day (`~/.claude` or `~/.codex`), skip the folder and name its slug in `.env` instead:

```dotenv
DROIDS_DEFAULT_CLAUDE=claude-max
DROIDS_DEFAULT_CODEX=codex-plus
```

Subscriptions that use an API key need no login: the server sends the key with the job.

## 5. Start the worker

```bash
php artisan droids:work
```

It prints the subscriptions it is signed in to, then polls the server every 5 seconds. Settings > Workers shows it as online. Assign a builder to a task on a linked project and the worker picks it up: the droid's step and a live log appear on the task.

The worker also answers conversations: chatting with a droid, planning sessions, "what's next" proposals, Reflect, design sessions and the candidates in **Assemble droid**, where an HR droid reads your request and proposes droids to hire. These run read-only in a shallow clone (Claude Code with only its Read, Grep and Glob tools, Codex in its read-only sandbox), and they go ahead of queued builds. The worker does one job at a time, so a question waits while a build is running.

Leave it running in a terminal tab, or keep it alive with something like `tmux`. `php artisan droids:work --once` runs a single job and exits, which is handy for a first test.

## Reviewer droids

Put a reviewer on a task and it reviews the pull request as soon as one is open: right away if the builder already opened it, otherwise when the builder's run finishes. The review runs read-only on the pull request's branch (Claude Code can also run `git diff`, `git log` and `git show`) and is posted on GitHub as a comment from the App, with inline notes. GitHub doesn't let the App approve or block a pull request it opened itself, so the verdict ("Looks good to merge" or "Changes requested") is in the comment and on the board.

When the reviewer asks for changes, the builder gets a fix run on the same branch with the review notes. Its commit lands on the open pull request, and the reviewer looks again. After two rounds of fixes, the task waits for you.

## Designer droids

Assemble a droid with the **Designer** role, then open a project's **Design** tab and press **Ask a designer**. The designer works on your worker in a clone of the repository. It reads the code to learn the product, then writes the project's design into `.droids/design/`:

- `system.md`: the design system, covering principles, color, type, spacing and components with their states.
- `tokens.css`: the tokens as CSS custom properties.
- `screens/<name>.html`: one self-contained HTML mockup per screen.

It can only write inside that folder: Claude Code gets its read tools plus Edit and Write limited to it, and Codex runs in its workspace-write sandbox. When it finishes, the worker sends the folder back. Droids saves it on the project, and you can preview each file in the Design tab. Ask again to change it: the designer starts from the files already there.

Every other run on the project gets the same files in `.droids/design/` in its clone. Builders, reviewers and planners are told to follow them. The folder is listed in the clone's `.git/info/exclude`, so a build never commits it.

## HR droids and new droids

**Assemble droid** shows candidates instead of a form. Describe the droid you need in the prompt box and the HR droid (the first droid with the **HR** role, else a planner) proposes a few candidates, each with a short pitch. Pick one and press **Assemble**. New droids belong to no project: they join one when you give them a task.

## Shared memory

Each project keeps a shared memory, `.droids/memory.md`, placed in every run's clone. Droids read it before they work and add what the next droid should know, such as how to run the tests or where a rule lives. Builders and designers edit the file; reviewers and conversations add facts through their answer. Two runs that change it at the same time are merged line by line. You can read and edit it in the project's **Memory** tab.

## How a run picks a subscription

- The worker only receives jobs for a subscription it is signed in to (or one that uses an API key).
- The schedule grid on the Subscriptions page decides which subscription is first choice for each hour. A paused hour holds runs in the queue.
- When a run hits a usage limit, that subscription is marked limited until its reset time, and the run is queued again on the next subscription for the same tool. After 3 attempts the run fails.

## Settings you can change

All optional, in the worker's `.env`:

| Key                                     | Default            | What it does                                                    |
| --------------------------------------- | ------------------ | --------------------------------------------------------------- |
| `DROIDS_WORKSPACE`                      | `~/.droids/work`   | Where clones are made. Each run's folder is deleted afterwards. |
| `DROIDS_LOGINS`                         | `~/.droids/logins` | Where the per-subscription login folders live.                  |
| `DROIDS_CLAUDE_BIN`, `DROIDS_CODEX_BIN` | `claude`, `codex`  | Full path to the CLI if it isn't on `PATH`.                     |
| `DROIDS_RUN_MINUTES`                    | `60`               | A run is stopped after this long.                               |

## Safety

The agents run with permission prompts turned off (`--dangerously-skip-permissions` for Claude, `--dangerously-bypass-approvals-and-sandbox` for Codex), so they can run any command as your user. Each run works in its own clone, but nothing stops a command from reaching the rest of your files. For real isolation, run the worker as a separate macOS user, in a virtual machine, or in a container.

The worker pushes with a short-lived token from the GitHub App, never with your own GitHub credentials. GitHub refuses pushes that change files under `.github/workflows` unless the App has the **Workflows** permission: add it (Read and write) under the App's **Permissions & events** on GitHub if your droids need to edit CI, then accept the new permission on the installation.
