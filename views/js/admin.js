// views/js/admin.js
$(document).ready(function() {
    // Inicializar chosen para los select múltiples
    $('.chosen').chosen({
        width: '100%',
        placeholder_text_multiple: 'Seleccione las categorías'
    });

    // Mostrar spinner durante la descarga
    $('button[name="downloadCsv"]').click(function() {
        if ($('select[name="EXPORT_CATEGORIES[]"]').val()) {
            $(this).prop('disabled', true);
            $(this).html('<i class="process-icon-loading"></i> Exportando...');
        } else {
            alert('Por favor, seleccione al menos una categoría.');
            return false;
        }
    });
});