{{-- The client-side PDF export for a console laid out as grouped cards
     (wired to the report toolbar's Export PDF button): each section an
     optional heading, each bar an optional label filled in the primary
     color, each item an optional bold headline over its name list, then the
     count. The options argument (from the toolbar's PDF options dialog)
     picks the orientation and paper. --}}
<script type="application/json" id="report-pdf-data">@json($pdfPayload)</script>
<script nonce="{{ $cspNonce ?? '' }}">
    window.conferenceReportPdf = async function (options) {
        const data = JSON.parse(document.getElementById('report-pdf-data').textContent);
        const margin = conferencePdf.PAGE_MARGIN;
        const branding = conferencePdf.branding;
        const doc = await conferencePdf.createDoc({ format: options.paper, orientation: options.orientation });
        let y = await conferencePdf.drawHeader(doc, { title: data.title });

        const pageHeight = doc.internal.pageSize.getHeight();
        const width = doc.internal.pageSize.getWidth() - 2 * margin;
        const lineHeight = (pt) => pt * 0.3528 * 1.25;
        const ensure = (needed) => {
            if (y + needed > pageHeight - margin) {
                doc.addPage();
                y = margin;
            }
        };
        const writeLines = (text, pt, style) => {
            doc.setFontSize(pt);
            doc.setFont('DejaVuSans', style);
            doc.splitTextToSize(text, width).forEach((line) => {
                ensure(lineHeight(pt));
                doc.text(line, margin, y, { baseline: 'top' });
                y += lineHeight(pt);
            });
        };

        doc.setTextColor(branding.text);
        data.sections.forEach((section) => {
            if (section.heading) {
                ensure(20);
                writeLines(section.heading, 14, 'bold');
                y += 3;
            }

            section.bars.forEach((bar) => {
                if (bar.label) {
                    ensure(18);
                    doc.setFillColor(branding.primary);
                    doc.rect(margin, y, width, 8, 'F');
                    doc.setFontSize(12);
                    doc.setFont('DejaVuSans', 'bold');
                    doc.setTextColor('#ffffff');
                    doc.text(bar.label, margin + 2, y + 4, { baseline: 'middle' });
                    doc.setTextColor(branding.text);
                    y += 10;
                }

                bar.items.forEach((item) => {
                    if (item.headline) {
                        writeLines(item.headline, 11, 'bold');
                    }
                    if (item.text) {
                        writeLines(item.text, 11, 'normal');
                    }
                    y += 2;
                });
                y += 3;
            });
        });

        ensure(20);
        writeLines(data.countLabel + ': ' + data.count, 12, 'bold');

        conferencePdf.save(doc, data.filename);
    };
</script>
