import re
def check_pdf(b):
    """Returns (ok, info): validates header, xref offsets, stream lengths, trailer, page count."""
    if not b.startswith(b'%PDF-1.4'): return False, 'header'
    if not b.rstrip().endswith(b'%%EOF'): return False, 'eof'
    sx = int(re.search(rb'startxref\s+(\d+)\s+%%EOF', b).group(1))
    if b[sx:sx+4] != b'xref': return False, 'startxref does not point at xref'
    m = re.match(rb'xref\n0 (\d+)\n', b[sx:]); n = int(m.group(1))
    ent = b[sx+m.end():sx+m.end()+20*n]
    for i in range(n):
        line = ent[i*20:(i+1)*20]
        assert len(line) == 20 and line[-2:] == b' \n', ('xref entry width', i)
        off = int(line[:10])
        if i == 0: assert line[17:18] == b'f'; continue
        if not b[off:].startswith(b'%d 0 obj' % i): return False, 'bad offset obj %d' % i
    tr = re.search(rb'trailer\s*<<(.*?)>>\s*startxref', b, re.S).group(1)
    assert int(re.search(rb'/Size (\d+)', tr).group(1)) == n
    for m in re.finditer(rb'/Length (\d+) >>\nstream\n', b):
        L = int(m.group(1)); s = m.end()
        if b[s+L:s+L+9] != b'endstream': return False, 'stream length'
    pages = len(re.findall(rb'/Type /Page /', b))
    cnt = int(re.search(rb'/Count (\d+)', b).group(1))
    if pages != cnt: return False, 'count'
    text = b' '.join(re.findall(rb'\((.*?)(?<!\\)\) Tj', b))
    return True, {'pages': pages, 'objs': n - 1, 'text': text.decode('latin-1')}
