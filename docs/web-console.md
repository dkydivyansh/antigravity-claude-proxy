# Web Management Console

The proxy includes a built-in, modern web interface for real-time monitoring and configuration. Access the console at: `http://localhost:8080` (default port).

![Antigravity Console](../images/webui-dashboard.png)

## Key Features

- **Real-time Dashboard**: Monitor request volume, active accounts, model health, and subscription tier distribution.
- **Visual Model Quota**: Track per-model usage and next reset times with color-coded progress indicators and draggable per-account threshold markers.
- **Account Management**: Add/remove Google accounts via OAuth, view subscription tiers (Free/Pro/Ultra), quota status, and per-account threshold settings.
- **Manual OAuth Mode**: Add accounts on headless servers by copying the OAuth URL and pasting the authorization code.
- **Claude CLI Configuration**: Edit your `~/.claude/settings.json` directly from the browser.
- **Persistent History**: Tracks request volume by model family for 30 days, persisting across server restarts.
- **Time Range Filtering**: Analyze usage trends over 1H, 6H, 24H, 7D, or All Time periods.
- **Smart Analysis**: Auto-select top 5 most used models or toggle between Family/Model views.
- **Live Logs**: Stream server logs with level-based filtering and search.
- **Quota Protection**: Set global or per-account minimum quota thresholds to proactively switch accounts before quota runs out.
- **Advanced Tuning**: Configure retries, timeouts, and debug mode on the fly.
- **Multi-language Interface**: Full support for English, Chinese (中文), Indonesian (Bahasa), Portuguese (PT-BR), and Turkish (Türkçe).
