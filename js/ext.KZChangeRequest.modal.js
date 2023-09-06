/**
 * Provide an AJAX modal loader for the change request form as a
 * function callable by an external extension or skin.
 * 
 * Usage:
 * mw.loader.using('ext.KZChangeRequest.modal', function() {
 *   window.kzcrAjax($('#MODAL_CONTENT_AREA'), onCloseHandler, onReadyHandler);
 * });
 */
window.kzcrAjax = function (jqContentArea, onClose, onReady = null) {
  var articleId = mw.config.get('wgArticleId');
  var api = new mw.Api();

  // AJAX form submit handler.
  var onSubmit = function (e) {
    e.preventDefault();
    var params = { action: 'kzcrModal' };
    var formValues = $("#kzcrChangeRequestForm").serializeArray();
    for (var i = formValues.length - 1; i >= 0; i--) {
      params[formValues[i].name] = formValues[i].value;
    }
    api.post(params).done(onApiResponse);
  };

  // AJAX content loader.
  var onApiResponse = function (data) {
    // Load HTML into content area.
    jqContentArea.html('<header><h1>' + data.title + '</h1></header>' + data.html);

    // Is the form displayed?
    if ($('#kzcrChangeRequestForm').length == 0) {
      // No form. Presumably confirmation page. Add a "Finish" button to close the modal.
      jqContentArea.append('<div class="kzcr-finish"><a href="#" id="kzcr-finish">' + data.finishMsg + '</a></div>');
      $('#kzcr-finish').click(onClose);
      if (onReady !== null) onReady();
    }
    else {
      // Add a "Cancel" button to the form that closes the modal.
      jqContentArea.append('<div class="kzcr-cancel"><a href="#" id="kzcr-cancel">' + data.cancelMsg + '</a></div>');
      $('#kzcr-cancel').click(onClose);

      // Add config variables to mw.config
      for (var name in data.config) {
        mw.config.set(name, data.config[name]);
      }

      // Attach AJAXian submit handler to form.
      $("#kzcrChangeRequestForm").submit(onSubmit);

      // Invoke ResourceLoader to ensure modules are loaded.
      mw.loader.using(data.modules, function () {
        // Make certain the form alterations are made for reCAPTCHA. It's possible the form has been dynamically reloaded.
        window.kzcrAlterForm();

        // Add bottom scripts. This loads the reCAPTCHA.
        $('body').append(data.bottomScripts);

        // Ready. Fire onReady and set focus on first form field.
        $('textarea[name=wpkzcrRequest]').trigger('focus');
        if (onReady !== null) onReady();
      });
    }
  };

  // Initial API callout to load the blank form.
  api.get({
    action: 'kzcrModal',
    articleId: articleId
  })
    .done(onApiResponse);
};