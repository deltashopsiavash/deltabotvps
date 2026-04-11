import asyncio
import json
import os
from aiohttp import web

# Import bot module (contains router + init_runtime singletons)
import bot as vpsbot


async def handle_update(request: web.Request) -> web.Response:
    try:
        raw = await request.text()
        if not raw:
            return web.json_response({"ok": False, "error": "empty body"}, status=400)
        data = json.loads(raw)
    except Exception as e:
        return web.json_response({"ok": False, "error": f"invalid json: {e}"}, status=400)

    # Ensure runtime is initialized
    if vpsbot.BOT_OBJ is None or vpsbot.DP_OBJ is None or vpsbot.DB_OBJ is None:
        await vpsbot.init_runtime(start_polling=False)

    try:
        # Feed update into dispatcher
        await vpsbot.DP_OBJ.feed_raw_update(vpsbot.BOT_OBJ, data, db=vpsbot.DB_OBJ)
    except Exception as e:
        # Best-effort: don't crash bridge
        return web.json_response({"ok": False, "error": str(e)}, status=500)

    return web.json_response({"ok": True})


async def main():
    host = os.getenv("BRIDGE_HOST", "127.0.0.1")
    port = int(os.getenv("BRIDGE_PORT", "9010"))

    app = web.Application()
    app.router.add_post("/update", handle_update)

    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, host, port)
    await site.start()

    # Keep running forever
    while True:
        await asyncio.sleep(3600)


if __name__ == "__main__":
    asyncio.run(main())
