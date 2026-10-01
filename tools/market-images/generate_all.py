"""Generate the WPA-style market images (53 states + 100 cities) with fal.ai via the media-gen skill.

Usage: python3 generate_all.py [--only key1,key2] [--workers 6]
Reads prompts.json next to this file; writes raw/<key>.png. Skips keys that already have a raw image.
"""
import argparse, json, os, shutil, subprocess, sys
from concurrent.futures import ThreadPoolExecutor, as_completed

HERE = os.path.dirname(os.path.abspath(__file__))
GEN = os.path.expanduser("~/.claude/skills/media-gen/scripts/generate.py")

STYLE = (
    "Rendered as a 1930s WPA Works Progress Administration silkscreen travel poster: a limited palette of "
    "5 to 6 flat ink colors ({palette}), bold simplified shapes with crisp edges, sky built from horizontal "
    "bands of graded color, stylized streamlined clouds, strong silhouettes, heroic low vantage point, visible "
    "screen-print ink texture and slight registration offset, Art Deco era National Park poster aesthetic. "
    "The artwork fills the entire canvas edge to edge with no margin. Absolutely no text, no lettering, "
    "no numbers, no signs, no logos, no title banner, no border frame."
)


def run(item, palettes, raw_dir):
    out = os.path.join(raw_dir, item["key"] + ".png")
    if os.path.exists(out):
        return item["key"], "skipped"
    prompt = item["scene"] + " " + STYLE.format(palette=palettes[item["code"]])
    p = subprocess.run(
        [sys.executable, GEN, "image", "--prompt", prompt, "--title", "revnm-" + item["key"],
         "--aspect-ratio", "4:3", "--resolution", "1K"],
        capture_output=True, text=True, timeout=600,
    )
    try:
        res = json.loads(p.stdout.strip().splitlines()[-1])
        shutil.copyfile(res["image_path"], out)
        return item["key"], "ok"
    except Exception:
        return item["key"], "FAILED: " + (p.stderr or p.stdout).strip()[-300:]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only", default="")
    ap.add_argument("--workers", type=int, default=6)
    a = ap.parse_args()
    data = json.load(open(os.path.join(HERE, "prompts.json")))
    items = data["items"]
    if a.only:
        want = set(a.only.split(","))
        items = [i for i in items if i["key"] in want]
    raw = os.path.join(HERE, "raw")
    os.makedirs(raw, exist_ok=True)
    fails = 0
    with ThreadPoolExecutor(a.workers) as ex:
        futs = [ex.submit(run, i, data["palettes"], raw) for i in items]
        for n, f in enumerate(as_completed(futs), 1):
            key, status = f.result()
            fails += status.startswith("FAILED")
            print(f"[{n}/{len(items)}] {key}: {status}", flush=True)
    print(f"done, {fails} failed")


if __name__ == "__main__":
    main()
