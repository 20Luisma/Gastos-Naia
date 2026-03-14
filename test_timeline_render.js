const domTemplate = { innerHTML: '' };
const timelineContainer = domTemplate;

function escapeHtml(str) { return str; }
function fileIcon(ext) { return "📄"; }

function renderComunicadosTimeline(items) {
    if (!items || items.length === 0) {
        timelineContainer.innerHTML = 'vacio';
        return;
    }

    let html = '';
    items.forEach(item => {
        const d = new Date(item.date);
        const dateStr = d.toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'short', day: 'numeric' });

        let fileAttachmentHtml = '';
        if (item.fileUrl) {
            const icon = fileIcon(item.fileType?.toLowerCase() || 'pdf');
            fileAttachmentHtml = `link html`;
        }

        html += `<div class="card">title: ${escapeHtml(item.title)} attr: ${fileAttachmentHtml}</div>`;
    });

    timelineContainer.innerHTML = html;
}

renderComunicadosTimeline([{ title: 'test', date: '2026-03-14' }]);
console.log("RESULT:", timelineContainer.innerHTML);
