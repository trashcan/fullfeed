var lastHistory = 0;

$(document).ready(function() {

    populateHistory();

    $('#expand').click(function() {
        var expandedUrl = false;
        var url = $('#rssUrl').val();
        if (expandedUrl = expandUrl($('#rssUrl').val())) {
            var current = lastHistory;
            addHistory(url, current);
            $.cookie('history_' + current, null);
            $.cookie('history_' + current, url, { expires: 7 });
            var urlHistory = $.parseJSON($.cookie('history'));
            if (urlHistory === null) { urlHistory = []; }
            urlHistory.push(current);
            $.cookie('history', $.toJSON(urlHistory));
        }
    });
    $('#expand').zclip({
        path: 'js/ZeroClipboard.swf',
        copy: function() { return expandUrl($('#rssUrl').val()); }
    });

    $('#rssUrl').hint();

    $('#feedbackButton').click(function() {
        var message = $('#feedbackText').val();
        var dataString = 'message=' + message;

        $.ajax({
            type:   'POST',
            url:    'feedback.php',
            data:   dataString,
            success: function() {
                $('#feedbackButton').slideUp(function() {
                    $('#feedbackText').slideUp();
                    $('#feedbackThankyou').slideDown();
                    $('#feedbackMoar').slideDown();
                });
            },
            error: function() {
                $('#feedbackButton').text('Doh, error :(');
            }
        });
    });

    $('#feedbackMoar').click(function() {
        $('#feedbackMoar').slideUp(function() {
            $('#feedbackThankyou').slideUp();
            $('#feedbackText').slideDown();
            $('#feedbackButton').slideDown();
        });
    });
});

function addHistory(url, hist) {

    $('<div>', {
        class:  'remove-bottom row',
        id:     'history_' + hist,
        style:  'display: none'
    }).appendTo('#history');

    $('<div>', {
        class:  'eight columns alpha',
        id:     'history_' + hist + '_cont'
    }).appendTo('#history_' + hist);

    $('<div>', {
        class:  'preexpanded',
        id:     'history_' + hist + '_url'
    }).appendTo('#history_' + hist + '_cont');

    $('<button>', {
        class:  'height32 right delete',
        type:   'button',
        id:     'history_' + hist + '_del'
    }).appendTo('#history_' + hist);

    $('<button>', {
        class:  'height32 right copy',
        type:   'button',
        id:     'history_' + hist + '_copy'
    }).appendTo('#history_' + hist);

    var fullurl = expandUrl(url);
    var urlstring = '<a href="' + fullurl + '">' + url + '</a>';

    $('#history_' + hist + '_url').html(urlstring);
    $('#history_' + hist + '_copy').text('Copy');
    $('#history_' + hist + '_del').text('Delete');

    $('#history_' + hist + '_del').click(function() {
        $(this).parent().slideUp(function() {
            var hist = $(this).attr('id').replace('history_', '');
            $('#history_' + hist + '_copy').zclip('remove');
            $(this).remove();
            $.cookie('history_' + hist, null);
            var urlHistory = $.parseJSON($.cookie('history'));
            if (urlHistory === null) { urlHistory = []; }
            urlHistory = _.without(urlHistory, parseInt(hist));
            $.cookie('history', $.toJSON(urlHistory));
        });
    });

    $('#history_' + hist).slideDown('slow', function() {
        $('#history_' + hist + '_copy').zclip({
            path: 'js/ZeroClipboard.swf',
            copy: function() { return expandUrl(url); }
        });
    });

    lastHistory++;
    return lastHistory;
}

function populateHistory() {
    var urlHistory = [];
    var i, lastHist;

    if ($.cookie('history')) {
        urlHistory = $.parseJSON($.cookie('history'));
    }

    for (i in urlHistory) {
        hist = urlHistory[i];
        if ($.cookie('history_' + hist)) {
            addHistory($.cookie('history_' + hist), hist);
            lastHist = hist;
        }
    }

    lastHist = parseInt(lastHist);
    if (isNaN(lastHist)) { lastHist = 0; }
    lastHistory = ++lastHist;
}

function expandUrl(url) {
    var expandedUrl = false;
    if (validateURL(url)) {
        expandedUrl = "http://" + document.domain + "/f/index.php?url=" + encodeURIComponent(url);
    }
    return expandedUrl;
}

function validateURL(url) {
    var urlRegex = new RegExp(
        "^(http:\/\/|https:\/\/|www.){1}([0-9A-Za-z]+\.)");
    return urlRegex.test(url);
}
