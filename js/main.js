jQuery(document).ready(function ($) {
  // listen for hashchange caused by the cycopay plugin
  var payload;

  const onhashchange = (event) => {
    const checkout_url = script_data?.checkout_url;
    const oldURL = event?.oldURL;
    if (oldURL.includes(checkout_url)) {
      openPopup();
    }
  };

  window.onhashchange = onhashchange;

  const openPopup = () => {
    const href = window.location.href;
    const strToReplace = "paypopup:";

    const result = href.substring(
      href.lastIndexOf(strToReplace) + strToReplace.length
    );

    const params = Object.fromEntries(new URLSearchParams(result));

    const token = params.token;
    if (token) {
      payload = JSON.parse(b64_to_utf8(token));
    }

    console.log("payload ", payload);

    const options = {
      ...payload,
    };

    payWithCycoPay(options);
  };

  const b64_to_utf8 = (str) => {
    return decodeURIComponent(escape(window.atob(str)));
  };

  const messageEventHandler = (event) => {
    let data = event.detail;

    const status = data?.status;
    const successURL = payload?.successURL;
    const failureUrl = payload?.failureURL;

    const paymentDetails = {
      status,
      apiKey: payload?.apiKey,
      metadata: payload?.metadata,
    };

    jQuery.ajax({
      type: "POST",
      url: script_data.ajaxurl,
      data: {
        action: "update_wc_status_ajax",
        paymentDetails,
      },
      success: function (data, textStatus, XMLHttpRequest) {
        if (status == "completed") {
          window.location.replace(successURL);
        } else {
          window.location.replace(failureUrl);
        }
      },
      error: function (XMLHttpRequest, textStatus, errorThrown) {
        if (status == "completed") {
          window.location.replace(successURL);
        } else {
          window.location.replace(failureUrl);
        }
      },
    });

    // ...
  };

  document.addEventListener("cycopay_message", messageEventHandler);
  console.log("listener added ");
  //   window.addEventListener("message", messageEventHandler, false);
});
