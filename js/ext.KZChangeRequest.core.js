$(function() {
  // Prepare the Change Request form's submit button for reCAPTCHA
  $("#kzcrButton button")
    .addClass('g-recaptcha')
    .attr('data-sitekey', mw.config.get('reCaptchaV3SiteKey'))
    .attr('data-callback', 'recaptchaOnSubmit')
    .attr('data-action', 'submit');

  // Provide reCAPTCHA with a submit handler.
  window.recaptchaOnSubmit = function(token) {
    $("#kzcrChangeRequestForm").submit();
  };
});
