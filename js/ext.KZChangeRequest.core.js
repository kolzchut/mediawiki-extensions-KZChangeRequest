mw.loader.using( ['mediawiki.util'] ).then( function () {

  // Invoke reCAPTHCA on form submission
  if (grecaptcha !== undefined && mw.config.values.wgReCaptchaV3SiteKey !== undefined) {
    $("#kzcrButton button").on("click", function(e) {
      e.preventDefault();
      grecaptcha.ready(function() {
        grecaptcha.execute(mw.config.values.wgReCaptchaV3SiteKey, {action: 'submit'}).then(function(token) {
          $("#kzcrChangeRequestForm").submit();
        });
      });
    });
  }

} );
