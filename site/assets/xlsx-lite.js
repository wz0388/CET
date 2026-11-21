/* 极简 xlsx 读写（无依赖）
 * 写：store 方式的 zip + inlineStr 单元格，Excel / WPS / Numbers 都能打开
 * 读：解析 zip 中央目录，deflate 的条目交给浏览器原生 DecompressionStream
 */
(function (global) {
  'use strict';

  /* ---------------- 工具 ---------------- */
  const enc = new TextEncoder();
  const dec = new TextDecoder('utf-8');

  const CRC_TABLE = (() => {
    const t = new Uint32Array(256);
    for (let i = 0; i < 256; i++) {
      let c = i;
      for (let k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[i] = c >>> 0;
    }
    return t;
  })();

  function crc32(buf) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }

  function concat(list) {
    let n = 0;
    list.forEach((b) => { n += b.length; });
    const out = new Uint8Array(n);
    let off = 0;
    list.forEach((b) => { out.set(b, off); off += b.length; });
    return out;
  }

  function dosStamp(d) {
    const time = ((d.getHours() & 31) << 11) | ((d.getMinutes() & 63) << 5) | ((d.getSeconds() / 2) & 31);
    const date = (((d.getFullYear() - 1980) & 127) << 9) | (((d.getMonth() + 1) & 15) << 5) | (d.getDate() & 31);
    return { time, date };
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]
    ));
  }

  function unesc(s) {
    return String(s == null ? '' : s)
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"').replace(/&apos;/g, "'")
      .replace(/&#(\d+);/g, (_, d) => String.fromCharCode(+d))
      .replace(/&amp;/g, '&');
  }

  function colName(i) {
    let s = '';
    i = i + 1;
    while (i > 0) {
      const m = (i - 1) % 26;
      s = String.fromCharCode(65 + m) + s;
      i = Math.floor((i - 1) / 26);
    }
    return s;
  }

  function colIndex(name) {
    let n = 0;
    for (let i = 0; i < name.length; i++) n = n * 26 + (name.charCodeAt(i) - 64);
    return n - 1;
  }

  /* ---------------- zip（仅 store） ---------------- */
  function zip(files) {
    const parts = [];
    const central = [];
    let offset = 0;
    const { time, date } = dosStamp(new Date());

    files.forEach((f) => {
      const name = enc.encode(f.name);
      const data = f.data;
      const crc = crc32(data);
      const size = data.length;

      const lh = new Uint8Array(30 + name.length);
      const lv = new DataView(lh.buffer);
      lv.setUint32(0, 0x04034b50, true);
      lv.setUint16(4, 20, true);
      lv.setUint16(6, 0x0800, true);   // UTF-8 名字
      lv.setUint16(8, 0, true);        // 0 = store
      lv.setUint16(10, time, true);
      lv.setUint16(12, date, true);
      lv.setUint32(14, crc, true);
      lv.setUint32(18, size, true);
      lv.setUint32(22, size, true);
      lv.setUint16(26, name.length, true);
      lv.setUint16(28, 0, true);
      lh.set(name, 30);
      parts.push(lh, data);

      const cd = new Uint8Array(46 + name.length);
      const cv = new DataView(cd.buffer);
      cv.setUint32(0, 0x02014b50, true);
      cv.setUint16(4, 20, true);
      cv.setUint16(6, 20, true);
      cv.setUint16(8, 0x0800, true);
      cv.setUint16(10, 0, true);
      cv.setUint16(12, time, true);
      cv.setUint16(14, date, true);
      cv.setUint32(16, crc, true);
      cv.setUint32(20, size, true);
      cv.setUint32(24, size, true);
      cv.setUint16(28, name.length, true);
      cv.setUint16(30, 0, true);
      cv.setUint16(32, 0, true);
      cv.setUint16(34, 0, true);
      cv.setUint16(36, 0, true);
      cv.setUint32(38, 0, true);
      cv.setUint32(42, offset, true);
      cd.set(name, 46);
      central.push(cd);

      offset += lh.length + size;
    });

    const cdSize = central.reduce((a, b) => a + b.length, 0);
    const eocd = new Uint8Array(22);
    const ev = new DataView(eocd.buffer);
    ev.setUint32(0, 0x06054b50, true);
    ev.setUint16(8, files.length, true);
    ev.setUint16(10, files.length, true);
    ev.setUint32(12, cdSize, true);
    ev.setUint32(16, offset, true);

    return concat(parts.concat(central, [eocd]));
  }

  /* ---------------- 生成 xlsx ---------------- */
  function cellXml(ref, value) {
    if (value === '' || value == null) return '';
    if (typeof value === 'number' && isFinite(value)) {
      return '<c r="' + ref + '"><v>' + value + '</v></c>';
    }
    return '<c r="' + ref + '" t="inlineStr"><is><t xml:space="preserve">' + esc(value) + '</t></is></c>';
  }

  function sheetXml(rows, widths) {
    const head =
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      + '<sheetViews><sheetView workbookViewId="0">'
      + '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
      + '</sheetView></sheetViews>'
      + (widths && widths.length
        ? '<cols>' + widths.map((w, i) =>
          '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + w + '" customWidth="1"/>').join('') + '</cols>'
        : '')
      + '<sheetData>';

    const body = rows.map((row, r) => {
      const cells = row.map((v, c) => cellXml(colName(c) + (r + 1), v)).join('');
      return '<row r="' + (r + 1) + '">' + cells + '</row>';
    }).join('');

    return head + body + '</sheetData></worksheet>';
  }

  /**
   * sheets: [{ name, rows: [[...]], widths: [18, 30] }]
   * 返回 Blob（可以直接下载）
   */
  function build(sheets) {
    const files = [];
    const add = (name, text) => files.push({ name, data: enc.encode(text) });

    const n = sheets.length;
    const overrides = sheets.map((s, i) =>
      '<Override PartName="/xl/worksheets/sheet' + (i + 1) + '.xml" '
      + 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>').join('');

    add('[Content_Types].xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      + '<Default Extension="xml" ContentType="application/xml"/>'
      + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      + overrides
      + '</Types>');

    add('_rels/.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      + '</Relationships>');

    add('xl/workbook.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
      + 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
      + sheets.map((s, i) =>
        '<sheet name="' + esc(s.name) + '" sheetId="' + (i + 1) + '" r:id="rId' + (i + 1) + '"/>').join('')
      + '</sheets></workbook>');

    add('xl/_rels/workbook.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      + sheets.map((s, i) =>
        '<Relationship Id="rId' + (i + 1) + '" '
        + 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
        + 'Target="worksheets/sheet' + (i + 1) + '.xml"/>').join('')
      + '</Relationships>');

    sheets.forEach((s, i) => {
      add('xl/worksheets/sheet' + (i + 1) + '.xml', sheetXml(s.rows || [], s.widths));
    });

    return new Blob([zip(files)], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
  }

  /* ---------------- 解析 xlsx ---------------- */
  async function inflateRaw(u8) {
    if (typeof DecompressionStream === 'undefined') return null;
    try {
      const ds = new DecompressionStream('deflate-raw');
      const stream = new Blob([u8]).stream().pipeThrough(ds);
      return new Uint8Array(await new Response(stream).arrayBuffer());
    } catch (e) {
      return null;
    }
  }

  async function unzip(buf) {
    const u8 = new Uint8Array(buf);
    const dv = new DataView(u8.buffer);
    // 从尾部找 EOCD
    let eocd = -1;
    for (let i = u8.length - 22; i >= 0 && i >= u8.length - 66000; i--) {
      if (dv.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
    }
    if (eocd < 0) throw new Error('不是有效的 xlsx（zip）文件');

    const count = dv.getUint16(eocd + 10, true);
    let ptr = dv.getUint32(eocd + 16, true);
    const out = {};

    for (let i = 0; i < count; i++) {
      if (dv.getUint32(ptr, true) !== 0x02014b50) break;
      const method = dv.getUint16(ptr + 10, true);
      const compSize = dv.getUint32(ptr + 20, true);
      const nameLen = dv.getUint16(ptr + 28, true);
      const extraLen = dv.getUint16(ptr + 30, true);
      const cmtLen = dv.getUint16(ptr + 32, true);
      const localOff = dv.getUint32(ptr + 42, true);
      const name = dec.decode(u8.subarray(ptr + 46, ptr + 46 + nameLen));

      // 本地头长度
      const lNameLen = dv.getUint16(localOff + 26, true);
      const lExtraLen = dv.getUint16(localOff + 28, true);
      const start = localOff + 30 + lNameLen + lExtraLen;
      const raw = u8.subarray(start, start + compSize);

      if (method === 0) {
        out[name] = raw;
      } else if (method === 8) {
        const inf = await inflateRaw(raw);
        if (inf) out[name] = inf;
      }
      ptr += 46 + nameLen + extraLen + cmtLen;
    }
    return out;
  }

  function parseShared(xml) {
    const list = [];
    if (!xml) return list;
    const re = /<si>([\s\S]*?)<\/si>/g;
    let m;
    while ((m = re.exec(xml))) list.push(textOf(m[1]));
    return list;
  }

  function textOf(inner) {
    const parts = [];
    const re = /<t[^>]*>([\s\S]*?)<\/t>/g;
    let m;
    while ((m = re.exec(inner))) parts.push(m[1]);
    return unesc(parts.join(''));
  }

  function parseSheet(xml, shared) {
    const rows = [];
    if (!xml) return rows;
    const rowRe = /<row[^>]*>([\s\S]*?)<\/row>/g;
    let rm;
    while ((rm = rowRe.exec(xml))) {
      const cells = [];
      const cellRe = /<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g;
      let cm;
      while ((cm = cellRe.exec(rm[1]))) {
        const attrs = cm[1] || '';
        const inner = cm[2] || '';
        const ref = /r="([A-Z]+)\d+"/.exec(attrs);
        const idx = ref ? colIndex(ref[1]) : cells.length;
        const tAttr = /t="([^"]+)"/.exec(attrs);
        const t = tAttr ? tAttr[1] : '';
        let val = '';
        if (t === 's') {
          const v = /<v>([\s\S]*?)<\/v>/.exec(inner);
          val = v ? (shared[parseInt(v[1], 10)] || '') : '';
        } else if (t === 'inlineStr' || t === 'str') {
          val = textOf(inner);
        } else {
          const v = /<v>([\s\S]*?)<\/v>/.exec(inner);
          val = v ? unesc(v[1]) : '';
        }
        cells[idx] = val;
      }
      rows.push(cells);
    }
    return rows;
  }

  function attrs(tag) {
    const o = {};
    const re = /([\w:]+)="([^"]*)"/g;
    let m;
    while ((m = re.exec(tag))) o[m[1]] = m[2];
    return o;
  }

  function workbookSheets(files) {
    const wb = files['xl/workbook.xml'] ? dec.decode(files['xl/workbook.xml']) : '';
    const rels = files['xl/_rels/workbook.xml.rels'] ? dec.decode(files['xl/_rels/workbook.xml.rels']) : '';

    const ridTarget = {};
    const rre = /<Relationship\b[^>]*\/?>/g;
    let m;
    while ((m = rre.exec(rels))) {
      const a = attrs(m[0]);
      if (a.Id && a.Target) ridTarget[a.Id] = a.Target;
    }

    const out = [];
    const sre = /<sheet\b[^>]*\/?>/g;
    while ((m = sre.exec(wb))) {
      const a = attrs(m[0]);
      const rid = a['r:id'] || a.id;
      if (!rid || !ridTarget[rid]) continue;
      let target = ridTarget[rid];
      target = target.charAt(0) === '/' ? target.slice(1) : 'xl/' + target;
      out.push({ name: unesc(a.name || ''), path: target });
    }
    return out;
  }

  /** 返回 [{ name, rows }] */
  async function parse(buf) {
    const files = await unzip(buf);
    const shared = parseShared(files['xl/sharedStrings.xml'] ? dec.decode(files['xl/sharedStrings.xml']) : '');
    const sheets = workbookSheets(files);
    const list = sheets.length
      ? sheets
      : [{ name: 'Sheet1', path: 'xl/worksheets/sheet1.xml' }];
    return list.map((s) => ({
      name: s.name,
      rows: parseSheet(files[s.path] ? dec.decode(files[s.path]) : '', shared),
    }));
  }

  global.XlsxLite = { build, parse };
})(window);
