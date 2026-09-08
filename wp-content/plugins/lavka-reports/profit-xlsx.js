/* Small, dependency-free OOXML snapshot writer. No formulas or external links. */
(function (scope) {
    'use strict';
    const encoder = new TextEncoder();
    const escapeXml = value => String(value == null ? '' : value)
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&apos;');
    const declaration = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    const ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const relns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const crcTable = Array.from({ length: 256 }, (_, n) => {
        for (let i = 0; i < 8; i++) n = (n & 1) ? 0xEDB88320 ^ (n >>> 1) : n >>> 1;
        return n >>> 0;
    });
    function crc32(bytes) {
        let crc = 0xFFFFFFFF;
        bytes.forEach(b => { crc = crcTable[(crc ^ b) & 255] ^ (crc >>> 8); });
        return (crc ^ 0xFFFFFFFF) >>> 0;
    }
    function concat(parts) {
        const result = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
        let offset = 0;
        parts.forEach(part => { result.set(part, offset); offset += part.length; });
        return result;
    }
    function zip(files) {
        const local = [], directory = [];
        let offset = 0;
        files.forEach(([path, xml]) => {
            const name = encoder.encode(path), data = encoder.encode(declaration + xml), crc = crc32(data);
            const header = new Uint8Array(30), v = new DataView(header.buffer);
            v.setUint32(0, 0x04034B50, true); v.setUint16(4, 20, true); v.setUint16(6, 0x800, true);
            v.setUint16(12, 33, true); v.setUint32(14, crc, true); v.setUint32(18, data.length, true);
            v.setUint32(22, data.length, true); v.setUint16(26, name.length, true);
            local.push(header, name, data);
            const central = new Uint8Array(46), c = new DataView(central.buffer);
            c.setUint32(0, 0x02014B50, true); c.setUint16(4, 20, true); c.setUint16(6, 20, true);
            c.setUint16(8, 0x800, true); c.setUint16(14, 33, true); c.setUint32(16, crc, true);
            c.setUint32(20, data.length, true); c.setUint32(24, data.length, true);
            c.setUint16(28, name.length, true); c.setUint32(42, offset, true);
            directory.push(central, name); offset += header.length + name.length + data.length;
        });
        const end = new Uint8Array(22), e = new DataView(end.buffer);
        e.setUint32(0, 0x06054B50, true); e.setUint16(8, files.length, true); e.setUint16(10, files.length, true);
        e.setUint32(12, directory.reduce((n, p) => n + p.length, 0), true); e.setUint32(16, offset, true);
        return concat([...local, ...directory, end]);
    }
    function column(index) {
        let result = '';
        for (index++; index; index = Math.floor((index - 1) / 26)) result = String.fromCharCode(65 + (index - 1) % 26) + result;
        return result;
    }
    function cell(value, ref, heading) {
        const numeric = value && typeof value === 'object' && value.type === 'number';
        const raw = numeric ? value.value : value;
        const text = String(raw == null ? '' : raw);
        // Excel has 15 significant digits; longer values remain exact text.
        if (numeric && /^-?\d+(\.\d+)?$/.test(text) && text.replace(/[-.]/g, '').replace(/^0+/, '').length <= 15) {
            return `<c r="${ref}" s="${heading ? 1 : value.money ? 2 : 3}"><v>${text}</v></c>`;
        }
        return `<c r="${ref}" s="${heading ? 1 : 0}" t="inlineStr"><is><t xml:space="preserve">${escapeXml(text)}</t></is></c>`;
    }
    function build(sheets) {
        if (!Array.isArray(sheets) || !sheets.length) throw new Error('No worksheets');
        const used = new Set();
        sheets = sheets.map((sheet, i) => {
            let name = String(sheet.name || `Sheet${i + 1}`).replace(/[\\/?*\[\]:]/g, ' ').replace(/^'+|'+$/g, '').slice(0, 31) || `Sheet${i + 1}`;
            const base = name; let suffix = 1;
            while (used.has(name.toLowerCase())) { const tail = ` (${++suffix})`; name = base.slice(0, 31 - tail.length) + tail; }
            used.add(name.toLowerCase()); return { ...sheet, name };
        });
        const files = [];
        const relation = (id, type, target) => `<Relationship Id="rId${id}" Type="${relns}/${type}" Target="${target}"/>`;
        files.push(['_rels/.rels', `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${relation(1, 'officeDocument', 'xl/workbook.xml')}</Relationships>`]);
        files.push(['xl/workbook.xml', `<workbook xmlns="${ns}" xmlns:r="${relns}"><sheets>${sheets.map((s, i) => `<sheet name="${escapeXml(s.name)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets></workbook>`]);
        files.push(['xl/_rels/workbook.xml.rels', `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${sheets.map((_, i) => relation(i + 1, 'worksheet', `worksheets/sheet${i + 1}.xml`)).join('')}${relation(sheets.length + 1, 'styles', 'styles.xml')}</Relationships>`]);
        files.push(['xl/styles.xml', `<styleSheet xmlns="${ns}"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red](#,##0.00);0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`]);
        sheets.forEach((s, i) => {
            const headings = new Set(s.headerRows || [1]);
            const width = Math.max(1, ...s.rows.map(r => r.length));
            const cols = Array.from({ length: width }, (_, n) => `<col min="${n + 1}" max="${n + 1}" width="${Number((s.widths || [])[n]) || 22}" customWidth="1"/>`).join('');
            const freeze = s.freezeRows || 0;
            const merged = new Set(s.mergeRows || []);
            const rows = s.rows.map((row, n) => {
                const lines = Math.max(1, ...row.map((value, c) => {
                    const text = String(value && typeof value === 'object' ? value.value ?? '' : value ?? '');
                    const capacity = Math.max(8, merged.has(n + 1) ? Array.from({length: width}, (_, i) => Number((s.widths || [])[i]) || 22).reduce((a, b) => a + b, 0) - 2 : (Number((s.widths || [])[c]) || 22) - 2);
                    return text.split('\n').reduce((count, line) => count + Math.max(1, Math.ceil(line.length / capacity)), 0);
                }));
                const height = Math.min(409, Math.max(headings.has(n + 1) ? 42 : 36, 15 * lines + 12));
                return `<row r="${n + 1}" ht="${height}" customHeight="1">${row.map((value, c) => cell(value, column(c) + (n + 1), headings.has(n + 1))).join('')}</row>`;
            }).join('');
            files.push([`xl/worksheets/sheet${i + 1}.xml`, `<worksheet xmlns="${ns}"><dimension ref="A1:${column(width - 1)}${Math.max(1, s.rows.length)}"/><sheetViews><sheetView showGridLines="0" workbookViewId="0">${freeze ? `<pane ySplit="${freeze}" topLeftCell="A${freeze + 1}" activePane="bottomLeft" state="frozen"/>` : ''}</sheetView></sheetViews><sheetFormatPr defaultRowHeight="36"/><cols>${cols}</cols><sheetData>${rows}</sheetData>${merged.size ? `<mergeCells count="${merged.size}">${[...merged].map(row => `<mergeCell ref="A${row}:${column(width - 1)}${row}"/>`).join('')}</mergeCells>` : ''}</worksheet>`]);
        });
        files.push(['[Content_Types].xml', `<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${sheets.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')}</Types>`]);
        return zip(files);
    }
    const api = { build };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else scope.LavkaProfitXlsx = api;
})(typeof window !== 'undefined' ? window : globalThis);
