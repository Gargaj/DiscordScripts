# A handful of PHP scripts for enhancing a demogroup's Discord experience

* `discord-countdown` - Daily reminders to approaching deadlines
* `discord-demopartynet-topicupdate` - Update a channel's topic daily with upcoming demoparties
* `discord-github` - Various reactions to a Github event (such as a `push` or a new release)
* `discord-pouet-comments` - Notifications about Pouët comments on a group's prods
* `discord-rss` - Notifications about an RSS feed
* `discord-social` - Notifications from Bluesky and Mastodon
* `discord-twitch-bot` - Notifications about Twitch streams

Each script should be placed in a crontab, and each of them requires a `config.json` with at least a Discord API token.
The rest should be self-explanatory.
