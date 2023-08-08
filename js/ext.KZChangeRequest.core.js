window.kzcrAlterForm = function() {
  // Don't make the same alterations twice.
  if ($("#kzcrButton button").hasClass('g-recaptcha')) return;

  // Prepare the Change Request form's submit button for reCAPTCHA
  $("#kzcrButton button")
    .addClass('g-recaptcha')
    .attr('data-sitekey', mw.config.get('reCaptchaV3SiteKey'));

  // Manually define a form element to return the reCAPTCHA token. We will set this manually below.
  $("#kzcrChangeRequestForm").append('<input id="recaptchaToken" type="hidden" name="g-recaptcha-response" value="" />');
};
$(window.kzcrAlterForm);

// To avoid race conditions, define all grecaptcha-dependent logic here. It will be called after the reCAPTCHA js is loaded.
window.grecaptchaOnJs = function() {
  var jqForm = $("#kzcrChangeRequestForm").get(0);
  $("#kzcrChangeRequestForm button[type=submit]").click(function(e) {
    e.preventDefault();

    // Manually invoke the browser's HTML form field validation prior to the reCAPTCHA callout.
    if (jqForm.checkValidity()) {
      // Form validated by the browser, so callout to reCAPTCHA.
      grecaptcha.ready(function() {
        grecaptcha.execute(mw.config.get('reCaptchaV3SiteKey'), {action: 'change_request'}).then(function(token) {
          $("#recaptchaToken").val(token);
          $("#kzcrChangeRequestForm").submit();
        });
      });
    }
    else {
      // Form failed validation by the browser, so show error(s).
      jqForm.reportValidity();
    }
  });
};

// And if the reCAPTCHA js has loaded already, we can run this now.
if (window.grecaptchaOnJsReady !== undefined) window.grecaptchaOnJs();
