jQuery(document).ready(function ($) {
    var openedWindow;

    const onhashchange = event => {
        openPopup();
        console.log('hash changed ')
    };

    window.onhashchange = onhashchange;

    const openPopup = () => {
        const href = window.location.href;
        const strToReplace = "paypopup:";

        const result = href.substring(href.lastIndexOf(strToReplace) + strToReplace.length);

        const params = Object.fromEntries(new URLSearchParams(result));

        const url = params?.url

        openedWindow = window.open(url,
            'popUpWindow',
            'height=800,width=500,left=100,top=100,resizable=yes,scrollbars=yes,toolbar=yes,menubar=no,location=no,directories=no, status=yes');
    }

    const messageEventHandler = (event) => {
        let data = event?.data;
        if (data) {
            data = JSON.parse(data)
        }

        // let allowed orign
        let allowedOrigin = [
            'https://pay.cycopay.com'
        ]

        // if its not among allowed origins then close window and do nothing
        // to prevent malicious attempts
        if (!allowedOrigin.includes(event?.origin)) {
            openedWindow.close()
        }

        const status = data?.paymentDetails?.status;
        const successURL = data?.paymentDetails?.successURL;
        const failureUrl = data?.paymentDetails?.failureURL;

        jQuery.ajax({
            type: 'POST',
            url: script_data.ajaxurl,
            data: {
                action: 'update_wc_status_ajax',
                paymentDetails: data?.paymentDetails
            },
            success: function (data, textStatus, XMLHttpRequest) {
                openedWindow.close();

                if (status == "completed") {
                    window.location.replace(successURL);
                } else {
                    window.location.replace(failureUrl);
                }
            },
            error: function (XMLHttpRequest, textStatus, errorThrown) {
                console.log('errorThrown ', errorThrown)
                openedWindow.close();

                if (status == "completed") {
                    window.location.replace(successURL);
                } else {
                    window.location.replace(failureUrl);
                }
            }
        });


        // ...
    }

    window.addEventListener("message", messageEventHandler, false);


});