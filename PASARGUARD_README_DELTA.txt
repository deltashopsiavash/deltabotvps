PasarGuard changes in this build:

1) Add PasarGuard panel:
   - Panel URL must include /dashboard if your installation needs it, e.g.
     https://delsub1.deltamarket2.ir:2053/dashboard
   - For subscription domain you may enter /empty. The bot will use the panel host but remove /dashboard for /sub links.

2) Add PasarGuard plan:
   - Use: مدیریت پلن ها -> افزودن پلن پاسارگارد
   - It only asks: title, price, category, PasarGuard server, days, GB volume, description.
   - It does NOT ask protocol, port type, inbound ID, connection row ID, network type, or host selection.

3) Purchase behavior:
   - Creates a PasarGuard user only.
   - Sets expire and data_limit based on the plan.
   - Automatically attaches PasarGuard group(s). If no group is stored in the plan, it fetches groups from the panel and attaches the available group(s). This matches your one-group setup.
   - Sends subscription link, not x-ui config rows.

Important:
- Keep your Hosts/Inbounds/Groups configured manually inside PasarGuard.
- If the panel has more than one group later, tell the bot developer to hard-code the desired group_id or store it in plan custom_sni.
