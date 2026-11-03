"""Rebuild GD companion fonts from bundled OFL fonts; requires fonttools.

libgd's legacy decoder cannot consume supplementary UTF-8, and some builds
also reject six-digit numeric references. Map those glyphs to unused BMP PUA
slots in server-only companion fonts. Browser/user text remains unchanged.
"""
import json
from pathlib import Path
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

root = Path(__file__).resolve().parents[1] / 'public/assets/branding-fonts'
sources = {
    'NotoSansSymbols2-Regular.ttf': ('BrandingSymbolsGD.ttf', 'Branding Symbols GD'),
    'NotoEmoji.ttf': ('BrandingEmojiGD.ttf', 'Branding Emoji GD'),
}
fonts = {name: TTFont(root / name) for name in sources}
used = set().union(*(set(font.getBestCmap()) for font in fonts.values()))
points = sorted(point for point in used if point > 0xFFFF)
slots = [point for point in range(0xE000, 0xF900) if point not in used]
assert len(points) <= len(slots), 'Not enough private-use slots'
aliases = dict(zip(points, slots))
for name, font in fonts.items():
    filename, family = sources[name]
    if 'fvar' in font:
        axes = {axis.axisTag: axis.defaultValue for axis in font['fvar'].axes}
        font = instantiateVariableFont(font, axes, inplace=False)
    cmap = font.getBestCmap()
    additions = {aliases[point]: glyph for point, glyph in cmap.items() if point in aliases}
    for table in font['cmap'].tables:
        if table.isUnicode() and table.format in (4, 12):
            table.cmap.update(additions)
    # Modified OFL fonts receive a distinct family and PostScript name.
    for record in font['name'].names:
        replacements = {1: family, 2: 'Regular', 3: family + ' 1.0',
                        4: family, 6: family.replace(' ', ''), 16: family, 17: 'Regular'}
        if record.nameID in replacements:
            record.string = replacements[record.nameID].encode(record.getEncoding())
    font.save(root / filename)
    loaded = TTFont(root / filename).getBestCmap()
    assert all(loaded[alias] == glyph for alias, glyph in additions.items())
    print(filename, len(additions), 'verified glyph aliases')
(root / 'gd-map.json').write_text(json.dumps(
    {chr(point): chr(alias) for point, alias in aliases.items()},
    ensure_ascii=False, separators=(',', ':')), encoding='utf-8')
(root / 'GD-FONTS-NOTICE.txt').write_text('''Branding Symbols GD and Branding Emoji GD are modified derivatives of the
bundled Noto Sans Symbols 2 and Noto Emoji fonts, distributed under OFL.txt.
Changes: static default-weight instance for Noto Emoji, supplementary glyph
aliases in unused BMP private-use slots, and renamed font families.
The companion fonts are for GD rendering only. Original user/browser Unicode
text is retained. Rebuild with tools/build-branding-gd-fonts.py (FontTools).
''')
