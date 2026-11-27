import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';

export async function makeDocument(title: string, lines: string[]) {
    const file = await PDFDocument.create();
    const page = file.addPage([595, 842]);
    const font = await file.embedFont(StandardFonts.Helvetica);
    const bold = await file.embedFont(StandardFonts.HelveticaBold);
    page.drawText('MEDCORE HEALTH / DEMO', { x: 48, y: 786, font: bold, size: 12, color: rgb(0.07, 0.37, 0.42) });
    page.drawText(title, { x: 48, y: 748, font: bold, size: 25 });
    page.drawText('Synthetic data only. Not a clinical or financial record.', { x: 48, y: 720, font, size: 11 });
    let cursor = 680;
    for (const line of lines) {
        const printable = line.replace(/[^\x20-\x7e]/g, ' ').trim();
        const rows = printable.match(/.{1,82}(?:\s|$)|.{1,82}/g) ?? [''];
        for (const row of rows) {
            if (cursor < 50) break;
            page.drawText(row.trim(), { x: 48, y: cursor, font, size: 11 });
            cursor -= 17;
        }
        cursor -= 7;
    }
    return file.save();
}
