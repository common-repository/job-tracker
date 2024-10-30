var _job_tracker_add_to_invoice_url;

jQuery(document).ready(
	function() {
		job_tooltip();
		
		jQuery(".job_tracker_make_editable")
		.click(
				function() {
					var element_name = jQuery(this).attr(
							'id');
					var width = jQuery(this).width() * 2;
					var original_content = jQuery(this)
							.html();
					var draw_input_field = "<input style='width: "
							+ width
							+ "px;' value='"
							+ jQuery(this).html()
							+ "' name='"
							+ element_name
							+ "' class='"
							+ element_name
							+ "'/>";

					if (!jQuery("input." + element_name).length > 0) {
						jQuery("#" + element_name).html(
								draw_input_field);
						jQuery("input." + element_name)
								.focus();
					}

		jQuery("input." + element_name).blur(
									function() {
										if (jQuery(
												"input."
														+ element_name)
												.val() == original_content
												|| jQuery(
														"input."
																+ element_name)
														.val() == '')
											jQuery(
													"#"
															+ element_name)
													.html(
															original_content);

									});
		})

		jQuery("#jobs-filter").submit( function() {
			if (jQuery("#jobs-filter select").val() == '-1') {
				return false;
			}
		});
		
		jQuery("a.job_tracker_custom_job_id").click(
			function() {
				jQuery("input.job_tracker_custom_job_id").toggle();
				return false;
		});
		
		jQuery('#jobs-filter .subsubsub a').click(
				function() {
					jQuery("#FilterTextBox").val(
							jQuery(this).attr('class'));
					var s = jQuery(this).attr('class')
							.toLowerCase().split(" ");
					jQuery("#job_sorter_table tr:hidden")
							.show();
					jQuery.each(s, function() {
						jQuery(
								"#job_sorter_table tr:visible .indexColumn:not(:contains('"
										+ this + "'))").parent()
								.hide();
					});
					return false;
				});

		jQuery("#job_sorter_table tr:has(td)").each(
				function() {
					var t = jQuery(this).text().toLowerCase(); // all
																// row
																// text
					jQuery("<td class='indexColumn'></td>").hide()
							.text(t).appendTo(this);
				});// each tr

		jQuery("#FilterTextBox").keyup( function() {
			var s = jQuery(this).val().toLowerCase().split(" ");
			// show all rows.

				jQuery("#job_sorter_table tr:hidden").show();
				jQuery.each(s,
						function() {
							jQuery(
									"#job_sorter_table tr:visible .indexColumn:not(:contains('"
											+ this + "'))")
									.parent().hide();
						});// each
			});// key up.

		jQuery('#new_job_tracker_form').submit( function() {
			if (jQuery("#job_subject").val() == '') {
				jQuery("#job_subject").addClass("error");
				jQuery("#job_subject").blur();
				return false;
			}
		});

		jQuery("#job_sorter_table").tablesorter( {
			headers : {
				0 : {
					sorter :false
				},
				6 : {
					sorter :false
				}
			}
		});
		
		jQuery("#job_tracker_show_archived").click( function() {
			if (jQuery("#job_sorter_table tr.job_tracker_archived").size() > 0) {
				jQuery(".job_tracker_archived").toggle();
			} else {
				jQuery("#job_sorter_table tbody").prepend('<tr class="alternate loading"><td colspan="7" >&nbsp;</td></tr>');
				jQuery("#job_sorter_table tbody").load(jQuery("#job_tracker_show_archived").attr('href')+" #job_sorter_table tbody tr", null, function() {
					jQuery(".job_tracker_archived").toggle();
				});
			}
			return false;
		});
		if (jQuery("#job_tracker_show_archived").hasClass('expanded')) {
			jQuery(".job_tracker_archived").toggle();
		}
		
		jQuery('#job_tracker_main_info .job_description_box').autogrow();
		jQuery('#job_tracker_main_info .autogrow').autogrow();
		jQuery('#job_tracker_main_info #add_itemized_item').bind('click', job_tracker_add_itemized_list_row);
		
		jQuery("#job_tracker_invoice_id").change(
			function() {
				if (jQuery("#job_tracker_invoice_id").val() && _job_tracker_add_to_invoice_url) {
					jQuery("#job_tracker_invoice_id_link").attr('href', _job_tracker_add_to_invoice_url+jQuery("#job_tracker_invoice_id").val());
				}
			}
		);
		
		jQuery("#job_tracker_action").change(
			function() {
				if (jQuery("#job_tracker_action :selected").text() == 'Delete') {
					var r = confirm("Are you sure you want to delete the selected invoice(s)?");
					if (r == true) {
						return true;
					} else {
						return false;
					}
				}
				if (jQuery("#job_tracker_action :selected").text() == 'Mark as Accepted' ||
					jQuery("#job_tracker_action :selected").text() == 'Mark as Working'	|| 
					jQuery("#job_tracker_action :selected").text() == 'Mark as Completed' || 
					jQuery("#job_tracker_action :selected").text() == 'Mark as Shipped') {
					jQuery("#job_tracker_status_message").val(prompt('Please enter a status message or leave blank'));
				}
				if (jQuery("#job_tracker_action :selected").text() == 'Mark as Shipped') {
					jQuery("#job_tracker_carrier").val(prompt('Please enter carrier name'));
					jQuery("#job_tracker_tracking_number").val(prompt('Please enter carrier tracking number'));
				}
		});
	}
);

this.job_tooltip = function() {
	/* CONFIG */
	xOffset = 10;
	yOffset = 20;
	// these 2 variable determine popup's distance from the cursor
	// you might want to adjust to get the right result
	/* END CONFIG */
	jQuery(".job_tracker_tooltip").hover(
			function(e) {
				this.t = this.title;
				this.title = "";
				jQuery("body").append(
						"<p id='job_tracker_tooltip'>" + this.t + "</p>");
				jQuery("#job_tracker_tooltip").css("top",
						(e.pageY - xOffset) + "px").css("left",
						(e.pageX + yOffset) + "px").fadeIn("fast");
			}, function() {
				this.title = this.t;
				jQuery("#job_tracker_tooltip").remove();
			});
	jQuery("a.job_tracker_tooltip").mousemove(
			function(e) {
				jQuery("#tooltip").css("top", (e.pageY - xOffset) + "px").css(
						"left", (e.pageX + yOffset) + "px");
			});
};

function job_tracker_add_itemized_list_row() {
	var lastRow = jQuery('#job_list tr:last').clone();
	var id = parseInt(jQuery('.id', lastRow).html()) + 1;
	
	jQuery('.id', lastRow).html(id);
	jQuery('.item_description', lastRow).attr('name',
			'itemized_list[' + id + '][description]');
	
	jQuery('.item_description', lastRow).val('');

	jQuery('#job_list').append(lastRow);
	
	return false;

}
