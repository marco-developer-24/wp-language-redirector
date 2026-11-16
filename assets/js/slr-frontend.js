(function($){
    function warnResolutionFailure(response, textStatus, errorThrown) {
        var payload = response && response.data ? response.data : {};
        var code = payload.code || textStatus || 'unknown_error';
        var requestId = payload.request_id || 'unavailable';

        console.warn(
            '[WP Language Redirector] Resolution failed.',
            {
                code: code,
                requestId: requestId,
                message: payload.message || errorThrown || 'No additional details available.'
            }
        );
    }

    $(document).ready(function(){
        $(document).on('click', '#wpm-languages a, a[data-wpm-lang]', function(e){
            // Preserve native browser behavior for modified clicks and non-navigation links.
            if (
                e.which !== 1 ||
                e.metaKey ||
                e.ctrlKey ||
                e.shiftKey ||
                e.altKey ||
                this.target === '_blank' ||
                this.hasAttribute('download')
            ) {
                return;
            }

            var link = this;
            var href = link.href || $(link).attr('href') || '';
            var lang = $(link).data('lang') || $(link).attr('data-lang') || null;

            if (!href) return;

            var languageUrl;
            try {
                languageUrl = new URL(href, window.location.origin);
            } catch(err) {
                return;
            }

            // The resolver only handles same-origin language navigation.
            if (
                !/^https?:$/.test(languageUrl.protocol) ||
                languageUrl.origin !== window.location.origin
            ) {
                return;
            }

            if (!lang) {
                lang = languageUrl.searchParams.get('lang');

                if (!lang) {
                    var segs = languageUrl.pathname.split('/').filter(Boolean);
                    if (segs.length && ['es', 'en', 'fr'].indexOf(segs[0]) !== -1) {
                        lang = segs[0];
                    }
                }
            }

            if (!lang) return;

            e.preventDefault();

            var data = {
                action: 'slr_get_redirect',
                nonce: slr.nonce,
                lang: lang,
                url: window.location.href
            };

            var objectId = $('body').data('post-id') || 0;
            if (objectId) data.object_id = objectId;

            var objectType = $('body').data('post-type') || '';
            if (objectType) data.object_type = objectType;

            $.post(slr.ajax_url, data, function(res){
                if (res && res.success && res.data.url) {
                    window.location.href = res.data.url;
                    return;
                }

                warnResolutionFailure(res, 'unresolved');
                window.location.href = href;
            }).fail(function(xhr, textStatus, errorThrown){
                warnResolutionFailure(xhr.responseJSON, textStatus, errorThrown);
                window.location.href = href;
            });
        });
    });
})(jQuery);
