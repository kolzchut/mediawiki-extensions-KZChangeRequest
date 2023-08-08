/**
 * Provide an AJAX modal loader for the change request form as a
 * function callable by an external extension or skin.
 */
window.kzcrAjax = function(jqContentArea, onClose) {
  var articleId = mw.config.get('wgArticleId');
  var api = new mw.Api();

  // Callout to the API's form loader action.
  api.get({
    action: 'kzcrLoad',
    articleId: articleId
  })
  .done(function (data) {
    // Load HTML into content area.
    jqContentArea.html('<header><h1>' + data.title + '</h1></header>' + data.html);

    // Add a "Cancel" button that closes the modal.
    jqContentArea.append('<div class="kzcr-cancel"><a href="#" id="kzcr-cancel">' + data.cancelMsg + '</a></div>');
    $('#kzcr-cancel').click(onClose);

    // Add config variables to mw.config
    for (var name in data.config) {
      mw.config.set(name, data.config[name]);
    }

    // Invoke ResourceLoader to ensure modules are loaded.
    mw.loader.using(data.modules, function() {
      // Make certain the form alterations are made for reCAPTCHA. It's possible the form has been dynamically reloaded.
      window.kzcrAlterForm();

      // Add bottom scripts. This loads the reCAPTCHA.
      $('body').append(data.bottomScripts);

      //@TODO: need to explicitly render the reCAPTCHA if form was dynamically reloaded?

      // Ready. Set focus on first form field.
      $('textarea[name=wpkzcrRequest]').trigger('focus');
    });
  });
};