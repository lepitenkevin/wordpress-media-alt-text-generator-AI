jQuery(document).ready(function ($) {
  // Ensure the wp.media object exists.
  if (typeof wp === "undefined" || !wp.media) {
    return;
  }

  // Extend the media view to add our button.
  wp.media.view.Attachment.Details.TwoColumn = wp.media.view.Attachment.Details.TwoColumn.extend(
    {
      // This is the function that runs when the view is rendered.
      render: function () {
        // Call the original render method first.
        wp.media.view.Attachment.Details.TwoColumn.__super__.render.apply(
          this,
          arguments
        );

        // Find the alt text input field.
        var altTextarea = this.$el.find(
          '.setting[data-setting="alt"] textarea'
        );

        // Check if the button already exists to prevent duplicates.
        if (altTextarea.length && !this.$el.find(".ai-generate-alt").length) {
          // Create the button.
          var button = $(
            '<button type="button" class="button ai-generate-alt" style="margin-top:6px;">Generate ALT Text (AI)</button>'
          );

          // Add the button after the alt text field.
          altTextarea.after(button);

          // Get the attachment ID from the media model.
          var attachmentId = this.model.get("id");

          // Add a click event listener to the button.
          button.on("click", function (e) {
            e.preventDefault();
            var $thisButton = $(this);

            $thisButton.text("Generating...").prop("disabled", true);

            // Make the AJAX call to our PHP handler.
            $.post(ajaxurl, {
              action: "ai_generate_alt",
              attachment_id: attachmentId,
              security: aiAltGenerator.nonce, // ✨ CHANGED: Send the nonce.
            })
              .done(function (response) {
                if (response.success) {
                  // On success, update the textarea value.
                  altTextarea.val(response.data);
                  // Manually trigger a change event so WordPress knows to save it.
                  altTextarea.trigger("change");
                } else {
                  // On failure, show an alert.
                  alert("Error: " + response.data);
                }
              })
              .fail(function () {
                alert("An unknown error occurred with the request.");
              })
              .always(function () {
                // Always reset the button text and state.
                $thisButton.text("Generate ALT Text (AI)").prop("disabled", false);
              });
          });
        }
      },
    }
  );
});

