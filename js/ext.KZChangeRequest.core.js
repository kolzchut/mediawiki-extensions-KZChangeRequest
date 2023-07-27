window.onLoadRecaptcha = function() {

  // Invoke reCAPTHCA on form submission
  if (mw.config.get('reCaptchaV3SiteKey') !== null) {
    grecaptcha.ready(function() {
      $("#kzcrButton button").on("click", function(e) {
        e.preventDefault();
        grecaptcha.execute(mw.config.get('reCaptchaV3SiteKey'), {action: 'submit'}).then(function(token) {
          console.log('grecaptcha.execute() completed');
          console.log(token);
          $("#kzcrChangeRequestForm").submit();
        });
      });
    });
  }

};
