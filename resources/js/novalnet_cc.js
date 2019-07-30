var $ = jQuery.noConflict();
var iframeWindow = false, targetOrigin = false;

function initIframe()
{
	var request = {
		callBack: 'createElements',
		customStyle: {
			labelStyle: $('#nn_cc_standard_style_label').val(),
			inputStyle: $('#nn_cc_standard_style_input').val(),
			styleText: $('#nn_cc_standard_style_css').val(),
			}
	};

	var iframe = $('#nn_iframe')[0];
	iframeWindow = iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
	targetOrigin = 'https://secure.novalnet.de';
	iframeWindow.postMessage(JSON.stringify(request), targetOrigin);
}

function getHash(e)
{	
	$('#novalnet_form_btn').attr('disabled',true);
	
	if ($('#nn_new_details').val().trim() == '1' && $('#nn_pan_hash').val().trim() == '' ) {
		e.preventDefault();
		e.stopImmediatePropagation();
		iframeWindow.postMessage(
			JSON.stringify(
				{
				'callBack': 'getHash',
				}
			), targetOrigin
		);
	} else {
		$('#nn_cc_form').submit();
	}
}

function reSize()
{
	if ($('#nn_iframe').length > 0) {
		var iframe = $('#nn_iframe')[0];
		iframeWindow = iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
		targetOrigin = 'https://secure.novalnet.de/';
		iframeWindow.postMessage(JSON.stringify({'callBack' : 'getHeight'}), targetOrigin);
	}
}

window.addEventListener(
	'message', function (e) {	
	if (e.origin === 'https://secure.novalnet.de') {
		var data = (typeof e.data === 'string') ? eval('(' + e.data + ')') : e.data;
		if (data['callBack'] == 'getHash') {
			if (data['error_message'] != undefined) {
				$('#novalnet_form_btn').attr('disabled',false);	
				alert($('<textarea />').html(data['error_message']).text());
			} else {
				$('#nn_pan_hash').val(data['hash']);
				$('#nn_unique_id').val(data['unique_id']);
				$('#nn_cc_form').submit();
			}
		}

		else if (data['callBack'] == 'getHeight') {
			$('#nn_iframe').attr('height', data['contentHeight']);
		}
	}
	}, false
);

$(document).ready(function () {
	$(window).resize(function() {
		reSize();
	});
	
	$('#savepayment').click(function() {
		
        if (!$('#savepayment').is(':checked')) {
            $('#save_payment').val('');
        } else {
            $('#save_payment').val('1');
        }
	});
	
	$('#nn_toggle_form').on('click',function(){
		if ($('#nn_new_card_details').css('display') == 'none'){
			$('#nn_new_card_details').show();
			$('#save_payment_block').show();
			$('#nn_saved_details').hide();
			$('#nn_new_details').val('1');
			$('#nn_toggle_form').html($('#nn_cc_display_text_saved').val());
		}else{
			$('#nn_new_card_details').hide();
			$('#save_payment_block').hide();
			$('#nn_saved_details').show();
			$('#nn_new_details').val('0');
			$('#nn_toggle_form').html($('#nn_cc_display_text_new').val());
		}
	});
});
