(function($){
    $(document).ready(function(){
        // Selector: asumiendo que el editor de menú dejó un contenedor con id #wpm-languages
        // Ajustá si tu selector es distinto.
        $(document).on('click', '#wpm-languages a, a[data-wpm-lang]', function(e){
            var link = this;
            var href = link.href || $(link).attr('href') || '';
            var lang = $(link).data('lang') || $(link).attr('data-lang') || null;

            // intentar extraer param 'lang' si no hay data attr
            if(!lang && href){
                try {
                    var u = new URL(href, window.location.origin);
                    lang = u.searchParams.get('lang');
                    // si el path tiene /en/ como primer segment, úsalo
                    if(!lang){
                        var segs = u.pathname.split('/').filter(Boolean);
                        if(segs.length) {
                            var first = segs[0];
                            if(['es','en','fr'].indexOf(first) !== -1) lang = first;
                        }
                    }
                } catch(err){}
            }

            // si no se detecta lang, dejar que el link actúe normal
            if(!lang) return;

            e.preventDefault();

            var data = {
                action: 'slr_get_redirect',
                nonce: slr.nonce,
                lang: lang,
                url: window.location.href
            };

            // attempt to include object id/type if present in DOM (optional)
            var objectId = $('body').data('post-id') || 0;
            if(objectId) data.object_id = objectId;
            var objectType = $('body').data('post-type') || '';
            if(objectType) data.object_type = objectType;

            $.post(slr.ajax_url, data, function(res){
                if(res && res.success && res.data.url){
                    window.location.href = res.data.url;
                } else {
                    // fallback simple
                    window.location.href = slr.home + lang + '/';
                }
            }).fail(function(){
                window.location.href = slr.home + lang + '/';
            });
        });
    });
})(jQuery);
