<script>
  (function () {
    function getAuftragIdFromUrl() {
      var pathMatch = window.location.pathname.match(/(?:^|\/)id-(\d+)(?:\.html)?(?:\/)?$/);

      if (pathMatch) {
        return pathMatch[1];
      }

      var params = new URLSearchParams(window.location.search);
      var id = params.get('id') || params.get('parentid') || '';
      var queryMatch = id.match(/\d+/);

      return queryMatch ? queryMatch[0] : '';
    }

    function createVermittlungMitteilungForm(auftragId) {
      var form = document.createElement('form');
      form.id = 'vermittlungMitteilungForm';
      form.action = '/pdf-form-filler/vermittlung-mitteilung';
      form.method = 'get';
      form.target = '_blank';
      form.className = 'vermittlung-mitteilung-form';

      var hiddenId = document.createElement('input');
      hiddenId.type = 'hidden';
      hiddenId.name = 'id';
      hiddenId.value = auftragId;
      form.appendChild(hiddenId);

      var button = document.createElement('button');
      button.type = 'submit';
      button.className = 'button is-primary';
      button.textContent = 'Vermittlung Mitteilung erstellen';
      button.disabled = !auftragId;
      form.appendChild(button);

      if (!auftragId) {
        var error = document.createElement('p');
        error.className = 'help is-danger';
        error.textContent = 'Keine gueltige Auftrags-ID in der URL gefunden.';
        form.appendChild(error);
      }

      return form;
    }

    document.addEventListener('DOMContentLoaded', function () {
      if (document.getElementById('vermittlungMitteilungForm')) {
        return;
      }

      var auftragId = getAuftragIdFromUrl();
      var form = createVermittlungMitteilungForm(auftragId);
      var mount = document.getElementById('vermittlungMitteilungMount')
        || document.querySelector('.mod_article')
        || document.body;

      mount.appendChild(form);
    });
  }());
</script>
