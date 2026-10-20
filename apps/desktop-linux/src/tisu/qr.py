"""Minimal QR code encoder (byte mode, error correction level M, versions 1–10).

Enough for Telegram login links (~70 characters → version 5). Written in pure Python so the
app needs no extra library in the Flatpak runtime or the .deb. Follows ISO/IEC 18004.
"""

import gi

gi.require_version("Gtk", "4.0")
from gi.repository import Gdk, Graphene, Gtk  # noqa: E402

# Error correction level M: (EC codewords per block, [(block count, data codewords per block), ...])
_BLOCKS_M = {
    1: (10, [(1, 16)]),
    2: (16, [(1, 28)]),
    3: (26, [(1, 44)]),
    4: (18, [(2, 32)]),
    5: (24, [(2, 43)]),
    6: (16, [(4, 27)]),
    7: (18, [(4, 31)]),
    8: (22, [(2, 38), (2, 39)]),
    9: (22, [(3, 36), (2, 37)]),
    10: (26, [(4, 43), (1, 44)]),
}
_ALIGN = {
    1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34],
    7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50],
}
_FORMAT_BITS_M = 0  # ECC level indicator for M


def _gf_mul(x, y):
    z = 0
    for i in range(7, -1, -1):
        z = (z << 1) ^ ((z >> 7) * 0x11D)
        z ^= ((y >> i) & 1) * x
    return z & 0xFF


def _rs_divisor(degree):
    result = [0] * (degree - 1) + [1]
    root = 1
    for _ in range(degree):
        for j in range(len(result)):
            result[j] = _gf_mul(result[j], root)
            if j + 1 < len(result):
                result[j] ^= result[j + 1]
        root = _gf_mul(root, 0x02)
    return result


def _rs_remainder(data, divisor):
    result = [0] * len(divisor)
    for b in data:
        factor = b ^ result.pop(0)
        result.append(0)
        for i, coef in enumerate(divisor):
            result[i] ^= _gf_mul(coef, factor)
    return result


def _codewords(data, version):
    ec_len, groups = _BLOCKS_M[version]
    capacity = sum(n * k for n, k in groups)
    count_bits = 8 if version < 10 else 16
    bits = [0, 1, 0, 0]  # byte mode
    bits += [(len(data) >> i) & 1 for i in range(count_bits - 1, -1, -1)]
    for byte in data:
        bits += [(byte >> i) & 1 for i in range(7, -1, -1)]
    bits += [0] * min(4, capacity * 8 - len(bits))
    bits += [0] * (-len(bits) % 8)
    stream = [int("".join(map(str, bits[i:i + 8])), 2) for i in range(0, len(bits), 8)]
    pad = 0xEC
    while len(stream) < capacity:
        stream.append(pad)
        pad ^= 0xEC ^ 0x11

    divisor = _rs_divisor(ec_len)
    blocks, pos = [], 0
    for count, size in groups:
        for _ in range(count):
            part = stream[pos:pos + size]
            pos += size
            blocks.append((part, _rs_remainder(part, divisor)))
    out = []
    for i in range(max(len(b[0]) for b in blocks)):
        out += [b[0][i] for b in blocks if i < len(b[0])]
    for i in range(ec_len):
        out += [b[1][i] for b in blocks]
    return out


class QrCode:
    def __init__(self, text, mask=None):
        data = text.encode("utf-8")
        for version in range(1, 11):
            ec_len, groups = _BLOCKS_M[version]
            count_bits = 8 if version < 10 else 16
            if 4 + count_bits + 8 * len(data) <= 8 * sum(n * k for n, k in groups):
                break
        else:
            raise ValueError("QR: matn juda uzun")
        self.version = version
        self.size = size = version * 4 + 17
        self.modules = [[False] * size for _ in range(size)]
        self._function = [[False] * size for _ in range(size)]
        self._draw_function_patterns()
        self._draw_codewords(_codewords(data, version))
        if mask is None:
            best = None
            for m in range(8):
                self._apply_mask(m)
                self._draw_format(m)
                score = self._penalty()
                if best is None or score < best[0]:
                    best = (score, m)
                self._apply_mask(m)  # XOR again = undo
            mask = best[1]
        self.mask = mask
        self._apply_mask(mask)
        self._draw_format(mask)

    # ── Function patterns ────────────────────────────────────
    def _set(self, x, y, dark):
        self.modules[y][x] = dark
        self._function[y][x] = True

    def _draw_function_patterns(self):
        size = self.size
        for i in range(size):
            self._set(6, i, i % 2 == 0)
            self._set(i, 6, i % 2 == 0)
        for cx, cy in ((3, 3), (size - 4, 3), (3, size - 4)):
            for dy in range(-4, 5):
                for dx in range(-4, 5):
                    x, y = cx + dx, cy + dy
                    if 0 <= x < size and 0 <= y < size:
                        self._set(x, y, max(abs(dx), abs(dy)) not in (2, 4))
        pos = _ALIGN[self.version]
        last = len(pos) - 1
        for i, ax in enumerate(pos):
            for j, ay in enumerate(pos):
                if (i, j) in ((0, 0), (0, last), (last, 0)):
                    continue
                for dy in range(-2, 3):
                    for dx in range(-2, 3):
                        self._set(ax + dx, ay + dy, max(abs(dx), abs(dy)) != 1)
        self._draw_format(0)  # reserve the area; real bits are drawn after masking
        if self.version >= 7:
            rem = self.version
            for _ in range(12):
                rem = (rem << 1) ^ ((rem >> 11) * 0x1F25)
            bits = self.version << 12 | rem
            for i in range(18):
                bit = (bits >> i) & 1 == 1
                a, b = size - 11 + i % 3, i // 3
                self._set(a, b, bit)
                self._set(b, a, bit)

    def _draw_format(self, mask):
        data = _FORMAT_BITS_M << 3 | mask
        rem = data
        for _ in range(10):
            rem = (rem << 1) ^ ((rem >> 9) * 0x537)
        bits = (data << 10 | rem) ^ 0x5412
        bit = [(bits >> i) & 1 == 1 for i in range(15)]
        size = self.size
        for i in range(6):
            self._set(8, i, bit[i])
        self._set(8, 7, bit[6])
        self._set(8, 8, bit[7])
        self._set(7, 8, bit[8])
        for i in range(9, 15):
            self._set(14 - i, 8, bit[i])
        for i in range(8):
            self._set(size - 1 - i, 8, bit[i])
        for i in range(8, 15):
            self._set(8, size - 15 + i, bit[i])
        self._set(8, size - 8, True)

    # ── Data ─────────────────────────────────────────────────
    def _draw_codewords(self, data):
        size, i = self.size, 0
        right = size - 1
        while right >= 1:
            if right == 6:
                right = 5
            for vert in range(size):
                for j in range(2):
                    x = right - j
                    upward = ((right + 1) & 2) == 0
                    y = size - 1 - vert if upward else vert
                    if not self._function[y][x] and i < len(data) * 8:
                        self.modules[y][x] = (data[i >> 3] >> (7 - (i & 7))) & 1 == 1
                        i += 1
            right -= 2

    def _apply_mask(self, mask):
        fns = [
            lambda x, y: (x + y) % 2 == 0,
            lambda x, y: y % 2 == 0,
            lambda x, y: x % 3 == 0,
            lambda x, y: (x + y) % 3 == 0,
            lambda x, y: (x // 3 + y // 2) % 2 == 0,
            lambda x, y: x * y % 2 + x * y % 3 == 0,
            lambda x, y: (x * y % 2 + x * y % 3) % 2 == 0,
            lambda x, y: ((x + y) % 2 + x * y % 3) % 2 == 0,
        ]
        f = fns[mask]
        for y in range(self.size):
            for x in range(self.size):
                if not self._function[y][x] and f(x, y):
                    self.modules[y][x] = not self.modules[y][x]

    def _penalty(self):
        m, size, score = self.modules, self.size, 0
        lines = m + [[m[y][x] for y in range(size)] for x in range(size)]
        finder_a = [True, False, True, True, True, False, True, False, False, False, False]
        finder_b = finder_a[::-1]
        for line in lines:
            run = 1
            for i in range(1, size + 1):
                if i < size and line[i] == line[i - 1]:
                    run += 1
                else:
                    if run >= 5:
                        score += run - 2
                    run = 1
            for i in range(size - 10):
                seg = line[i:i + 11]
                if seg == finder_a or seg == finder_b:
                    score += 40
        for y in range(size - 1):
            for x in range(size - 1):
                c = m[y][x]
                if c == m[y][x + 1] == m[y + 1][x] == m[y + 1][x + 1]:
                    score += 3
        dark = sum(sum(row) for row in m)
        total = size * size
        score += abs(dark * 20 - total * 10) // total * 10
        return score



class QrView(Gtk.Widget):
    """Draws a QR code as sharp vector squares (crisp at any scale factor).

    Always black on white with a quiet zone, so phone cameras read it in dark mode too.
    """

    __gtype_name__ = "TisuQrView"

    def __init__(self, size=200, border=4):
        super().__init__()
        self._qr = None
        self._size = size
        self._border = border
        self.set_halign(Gtk.Align.CENTER)
        self.set_tooltip_text("Telefon kamerasi bilan skanerlang")
        self.update_property([Gtk.AccessibleProperty.LABEL], ["Kirish uchun QR kod"])

    def set_text(self, text):
        self._qr = QrCode(text) if text else None
        self.queue_draw()

    def do_measure(self, orientation, for_size):
        return self._size, self._size, -1, -1

    def do_snapshot(self, snapshot):
        w, h = self.get_width(), self.get_height()
        white, black = Gdk.RGBA(), Gdk.RGBA()
        white.parse("#ffffff")
        black.parse("#000000")
        snapshot.append_color(white, Graphene.Rect().init(0, 0, w, h))  # the whole tile is the quiet zone
        if not self._qr:
            return
        n = self._qr.size + 2 * self._border
        cell = max(1, min(w, h) // n)  # whole pixels per module: sharp edges, no seams
        side = cell * n
        ox, oy = (w - side) // 2, (h - side) // 2
        for y, row in enumerate(self._qr.modules):
            x = 0
            while x < len(row):
                if row[x]:
                    start = x
                    while x < len(row) and row[x]:
                        x += 1
                    snapshot.append_color(black, Graphene.Rect().init(
                        ox + (start + self._border) * cell, oy + (y + self._border) * cell, (x - start) * cell, cell))
                else:
                    x += 1
