#!/usr/bin/env python3
"""Regenerate the .wordpress-org icons and banners with rsvg-convert.

The banner keeps the catalog gradient, the app icon and the plugin name on the
left, and adds the screenshot on the right in a tilted window that runs off the
edge of the banner. Both banner sizes are the same drawing at two scales, which
is what wordpress.org expects of the 772x250 / 1544x500 pair.

Requires Python 3 and rsvg-convert.
Run: python3 scripts/generate-wordpress-org-banners.py
For an SVN checkout, pass --assets-dir ../assets from trunk.
"""
import argparse
import base64
import os
import subprocess

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PLUGIN_DIR = os.path.dirname(SCRIPT_DIR)
ASSETS_DIR = os.path.join(PLUGIN_DIR, '.wordpress-org')

GRADIENT_FROM = '#11998e'
GRADIENT_TO = '#38ef7d'
TITLE = 'Familypedia'

W, H = 772.0, 250.0          # the drawing's own coordinate system
AVG_CHAR = 0.55              # Lato Bold, em per character, near enough to fit text


def data_uri(path):
    mime = 'image/jpeg' if path.endswith(('.jpg', '.jpeg')) else 'image/png'
    with open(path, 'rb') as fh:
        return 'data:%s;base64,%s' % (mime, base64.b64encode(fh.read()).decode())


def wrap(title, limit):
    """Break the title into at most two lines of roughly equal length."""
    words = title.split()
    if len(words) < 2 or len(title) <= limit:
        return [title]
    best, best_cost = None, None
    for i in range(1, len(words)):
        a, b = ' '.join(words[:i]), ' '.join(words[i:])
        cost = max(len(a), len(b))
        if best_cost is None or cost < best_cost:
            best, best_cost = (a, b), cost
    return list(best)


def banner_svg(icon, shot, c1, c2, title):
    # Left column: icon, then the name beside it.
    icon_box = 96.0
    icon_x, icon_y = 40.0, (H - icon_box) / 2.0
    text_x = icon_x + icon_box + 26.0
    text_w = 470.0 - text_x

    lines = wrap(title, int(text_w / (AVG_CHAR * 46.0)))
    longest = max(len(l) for l in lines)
    font = min(52.0, text_w / (AVG_CHAR * longest))
    line_h = font * 1.16
    first_y = H / 2.0 + font * 0.34 - line_h * (len(lines) - 1) / 2.0
    text = ''.join(
        '<text x="{x}" y="{y}" font-family="Lato, DejaVu Sans, sans-serif" font-size="{fs}" '
        'font-weight="700" fill="#ffffff">{t}</text>'.format(
            x=text_x, y=round(first_y + i * line_h, 2), fs=round(font, 2),
            t=l.replace('&', '&amp;'))
        for i, l in enumerate(lines)
    )

    # Right: the screenshot in a tilted window that runs off the banner edge.
    win_w, win_h = 330.0, 208.0
    cx, cy = 645.0, 122.0
    x, y = cx - win_w / 2.0, cy - win_h / 2.0
    chrome = 17.0                        # the window's title bar
    r = 9.0
    tilt = 'rotate(-7 %s %s)' % (cx, cy)
    dots = ''.join(
        '<circle cx="%s" cy="%s" r="2.6" fill="#ffffff" opacity="0.5"/>'
        % (x + 15 + i * 12, y + chrome / 2.0) for i in range(3)
    )
    window = (
        '<g transform="{tilt}">'
        '<rect x="{x}" y="{sy}" width="{w}" height="{h}" rx="{r}" ry="{r}" fill="#000000" '
        'opacity="0.38" filter="url(#soft)"/>'
        '<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" ry="{r}" fill="#1d2327"/>'
        '{dots}'
        '<image x="{x}" y="{iy}" width="{w}" height="{ih}" xlink:href="{shot}" '
        'preserveAspectRatio="xMidYMin slice" clip-path="url(#shotClip)"/>'
        '<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" ry="{r}" fill="none" '
        'stroke="#ffffff" stroke-opacity="0.28" stroke-width="1.5"/>'
        '</g>'
    ).format(tilt=tilt, x=x, y=y, sy=y + 7, w=win_w, h=win_h, r=r, dots=dots,
             iy=y + chrome, ih=win_h - chrome, shot=shot)
    shot_clip = (
        '<clipPath id="shotClip"><rect x="{x}" y="{iy}" width="{w}" height="{ih}" rx="{r}" '
        'ry="{r}"/></clipPath>'
    ).format(x=x, iy=y + chrome, w=win_w, ih=win_h - chrome, r=r * 0.6)

    return (
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
        'width="{W}" height="{H}" viewBox="0 0 {W} {H}">'
        '<defs>'
        '<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
        '<stop offset="0%" stop-color="{c1}"/><stop offset="100%" stop-color="{c2}"/>'
        '</linearGradient>'
        '<linearGradient id="scrim" x1="0" y1="0" x2="1" y2="0">'
        '<stop offset="0%" stop-color="#000000" stop-opacity="0.22"/>'
        '<stop offset="62%" stop-color="#000000" stop-opacity="0"/>'
        '</linearGradient>'
        '<filter id="soft" x="-25%" y="-25%" width="150%" height="150%">'
        '<feGaussianBlur stdDeviation="7"/></filter>'
        '{shot_clip}'
        '</defs>'
        '<rect width="{W}" height="{H}" fill="url(#bg)"/>'
        '<rect width="{W}" height="{H}" fill="url(#scrim)"/>'
        '{window}'
        '<image x="{ix}" y="{iy}" width="{ib}" height="{ib}" xlink:href="{icon}"/>'
        '{text}'
        '</svg>'
    ).format(W=W, H=H, c1=c1, c2=c2, shot_clip=shot_clip, window=window,
             ix=icon_x, iy=icon_y, ib=icon_box, icon=icon, text=text)


def rasterize(svg_text, png_path, width, height):
    tmp = png_path + '.svg'
    with open(tmp, 'w') as fh:
        fh.write(svg_text)
    subprocess.run(['rsvg-convert', '-w', str(width), '-h', str(height),
                    '-o', png_path, tmp], check=True)
    os.unlink(tmp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--assets-dir', default=ASSETS_DIR,
                        help='Asset directory (default: .wordpress-org in the plugin checkout)')
    assets_dir = parser.parse_args().assets_dir
    shot = os.path.join(assets_dir, 'screenshot-1.png')
    icon_source = os.path.join(PLUGIN_DIR, 'assets', 'icon.svg')
    with open(icon_source) as fh:
        icon_svg = fh.read()
    for size in (128, 256):
        rasterize(icon_svg, os.path.join(assets_dir, 'icon-%sx%s.png' % (size, size)), size, size)
    icon = os.path.join(assets_dir, 'icon-256x256.png')
    svg = banner_svg(
        data_uri(icon),
        data_uri(shot),
        GRADIENT_FROM,
        GRADIENT_TO,
        TITLE,
    )
    rasterize(svg, os.path.join(assets_dir, 'banner-772x250.png'), 772, 250)
    rasterize(svg, os.path.join(assets_dir, 'banner-1544x500.png'), 1544, 500)
    print('generated Familypedia icons and banners')


if __name__ == '__main__':
    main()
