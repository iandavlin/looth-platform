#!/usr/bin/env python3
# Isolated-tab CDP probe: creates its OWN Chrome target so concurrent agents
# don't clobber each other's navigation. Usage:
#   cdp_tab.py <url> <probe_js> [screenshot_path] [width] [height]
import sys, json, time, base64, urllib.request, websocket

URL   = sys.argv[1]
PROBE = sys.argv[2] if len(sys.argv) > 2 else "({href:location.href})"
SHOT  = sys.argv[3] if len(sys.argv) > 3 else ""
W     = int(sys.argv[4]) if len(sys.argv) > 4 else 0
H     = int(sys.argv[5]) if len(sys.argv) > 5 else 0

ver = json.load(urllib.request.urlopen("http://127.0.0.1:9222/json/version"))
bws = ver["webSocketDebuggerUrl"]
b = websocket.create_connection(bws, timeout=45, max_size=None)
_bid = 0
def bcmd(method, params=None):
    global _bid; _bid += 1; mid = _bid
    b.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
    while True:
        m = json.loads(b.recv())
        if m.get("id") == mid: return m

# Create our own blank target and attach (flatten = sessionId on this socket).
tid = bcmd("Target.createTarget", {"url": "about:blank"})["result"]["targetId"]
sid = bcmd("Target.attachToTarget", {"targetId": tid, "flatten": True})["result"]["sessionId"]

_sid = 0
def scmd(method, params=None, wait=True):
    global _sid; _sid += 1; mid = _sid
    b.send(json.dumps({"id": mid, "method": method, "params": params or {}, "sessionId": sid}))
    if not wait: return None
    while True:
        m = json.loads(b.recv())
        if m.get("id") == mid and m.get("sessionId") == sid: return m

errors = []
scmd("Runtime.enable"); scmd("Page.enable"); scmd("Log.enable")
if W and H:
    scmd("Emulation.setDeviceMetricsOverride",
         {"width": W, "height": H, "deviceScaleFactor": 1, "mobile": W < 700})
scmd("Page.navigate", {"url": URL})

deadline = time.time() + 5
b.settimeout(0.6)
try:
    while time.time() < deadline:
        try: m = json.loads(b.recv())
        except Exception: continue
        if m.get("sessionId") != sid: continue
        meth = m.get("method", "")
        if meth == "Runtime.consoleAPICalled":
            p = m["params"]
            if p.get("type") in ("error", "warning"):
                txt = " ".join(str(a.get("value", a.get("description", ""))) for a in p.get("args", []))
                errors.append(p["type"] + ": " + txt[:300])
        elif meth == "Log.entryAdded":
            e = m["params"]["entry"]
            if e.get("level") in ("error", "warning"):
                errors.append(e["level"] + ": " + str(e.get("text", ""))[:300])
finally:
    b.settimeout(45)

r = scmd("Runtime.evaluate",
         {"expression": "JSON.stringify(" + PROBE + ")", "returnByValue": True, "awaitPromise": True})
val = r.get("result", {}).get("result", {}).get("value")
print("PROBE:", val)
print("CONSOLE_ERRORS:", json.dumps(errors[:25], indent=2))

if SHOT:
    s = scmd("Page.captureScreenshot", {"format": "png"})
    data = s.get("result", {}).get("data")
    if data:
        open(SHOT, "wb").write(base64.b64decode(data))
        print("SHOT_WRITTEN:", SHOT)

bcmd("Target.closeTarget", {"targetId": tid})
b.close()
