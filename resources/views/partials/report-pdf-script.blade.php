{{-- The standard client-side report PDF export (wired to the report
     toolbar's Export PDF button). Renders the payload's rows as one table.
     The options argument (from the toolbar's PDF options dialog) picks the
     orientation and paper. --}}
<script type="application/json" id="report-pdf-data">@json($pdfPayload)</script>
<script nonce="{{ $cspNonce ?? '' }}">
    window.conferenceReportPdf = async function (options) {
        const data = JSON.parse(document.getElementById('report-pdf-data').textContent);
        const margin = conferencePdf.PAGE_MARGIN;
        const doc = await conferencePdf.createDoc({ format: options.paper, orientation: options.orientation });
        const width = doc.internal.pageSize.getWidth() - margin * 2;
        let y = await conferencePdf.drawHeader(doc, { title: data.title });

        // The report's own admin-authored header, shown once beneath the
        // standard title/logo header.
        if (data.header) {
            const lines = doc.splitTextToSize(data.header, width);
            doc.text(lines, margin, y, { baseline: 'top' });
            y += lines.length * 5 + 4;
        }

        const tableOptions = {
            margin: { left: margin, right: margin, top: margin, bottom: margin },
            theme: 'plain',
            styles: { font: 'DejaVuSans', fontSize: 11, cellPadding: 2, textColor: conferencePdf.branding.text },
            headStyles: { fillColor: conferencePdf.branding.primary, textColor: '#ffffff', fontStyle: 'bold' },
            bodyStyles: { lineWidth: { bottom: 0.1 }, lineColor: '#cccccc', minCellHeight: 10 },
            startY: y,
            head: [data.head],
            body: data.rows,
        };
        doc.autoTable(tableOptions);

        // The report's own admin-authored footer, shown once beneath the table.
        if (data.footer) {
            const lines = doc.splitTextToSize(data.footer, width);
            doc.text(lines, margin, doc.lastAutoTable.finalY + 8, { baseline: 'top' });
        }

        conferencePdf.save(doc, data.filename);
    };
</script>
