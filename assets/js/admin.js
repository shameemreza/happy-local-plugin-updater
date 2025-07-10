/**
 * Local Plugin Updater Admin JavaScript
 */
(function ($) {
  "use strict";

  // Check for updates
  function checkForUpdates() {
    var $button = $("#lpu-check-updates");
    var $results = $("#lpu-update-results");

    // Show loading state
    $button.prop("disabled", true).text(LPU.checking);

    // Make AJAX request
    $.ajax({
      url: LPU.ajax_url,
      type: "POST",
      data: {
        action: "lpu_check_updates",
        nonce: LPU.nonce,
      },
      success: function (response) {
        // Reset button
        $button.prop("disabled", false).text("Check for Updates");

        // Handle response
        if (response.success) {
          var updates = response.data.updates;
          var count = response.data.count;

          // Update the results
          if (count > 0) {
            var html = '<table class="widefat striped lpu-updates-table">';
            html += "<thead><tr>";
            html += "<th>Plugin</th>";
            html += "<th>Current Version</th>";
            html += "<th>New Version</th>";
            html += "<th>Actions</th>";
            html += "</tr></thead><tbody>";

            $.each(updates, function (index, update) {
              html += "<tr>";
              html += "<td>" + update.name + "</td>";
              html += "<td>" + update.current_version + "</td>";
              html += "<td>" + update.new_version + "</td>";
              html +=
                '<td><button type="button" class="button lpu-update-plugin" data-plugin="' +
                update.plugin +
                '">Update</button></td>';
              html += "</tr>";
            });

            html += "</tbody></table>";
            $results.html(html);

            // Update last checked
            $(".lpu-last-checked").text("Last checked: just now");
          } else {
            $results.html(
              '<p class="lpu-no-updates">No updates available.</p>'
            );
          }
        } else {
          $results.html(
            '<div class="lpu-notice lpu-notice-error"><p>' +
              (response.data.message || "An error occurred.") +
              "</p></div>"
          );
        }
      },
      error: function () {
        // Reset button
        $button.prop("disabled", false).text("Check for Updates");

        // Show error
        $results.html(
          '<div class="lpu-notice lpu-notice-error"><p>' +
            LPU.error +
            "</p></div>"
        );
      },
    });
  }

  // Update plugin
  function updatePlugin(plugin) {
    var $button = $('.lpu-update-plugin[data-plugin="' + plugin + '"]');
    var originalText = $button.text();

    // Show loading state
    $button.prop("disabled", true).text(LPU.updating);

    // Make AJAX request
    $.ajax({
      url: LPU.ajax_url,
      type: "POST",
      data: {
        action: "lpu_update_plugin",
        nonce: LPU.nonce,
        plugin: plugin,
      },
      success: function (response) {
        if (response.success) {
          // Show success message
          var $row = $button.closest("tr");
          $row.fadeOut(300, function () {
            // Check if this was the last update
            if ($(".lpu-updates-table tbody tr:visible").length === 0) {
              $("#lpu-update-results").html(
                '<p class="lpu-no-updates">No updates available.</p>'
              );
            } else {
              $row.remove();
            }
          });

          // Show notification
          $(
            '<div class="notice notice-success is-dismissible"><p>' +
              LPU.updated +
              "</p></div>"
          )
            .insertAfter(".wp-header-end")
            .delay(5000)
            .fadeOut(500);

          // Update the UI to show the plugin as up-to-date
          updatePluginUI(plugin, response.data);

          // Set a flag in sessionStorage that we updated a plugin
          sessionStorage.setItem("lpu_updated", "true");

          // Force update counters after a short delay
          setTimeout(function () {
            forceUpdateCounts();
          }, 500);
        } else {
          // Show error and reset button
          $button.prop("disabled", false).text(originalText);

          // Show notification
          $(
            '<div class="notice notice-error is-dismissible"><p>' +
              (response.data.message || LPU.error) +
              "</p></div>"
          ).insertAfter(".wp-header-end");
        }
      },
      error: function () {
        // Reset button
        $button.prop("disabled", false).text(originalText);

        // Show notification
        $(
          '<div class="notice notice-error is-dismissible"><p>' +
            LPU.error +
            "</p></div>"
        ).insertAfter(".wp-header-end");
      },
    });
  }

  // Update admin bar counters
  function updateAdminBarCounter() {
    // Update plugin update count in admin bar
    var $adminBarUpdates = $("#wp-admin-bar-updates");
    if ($adminBarUpdates.length) {
      var $adminBarCounter = $adminBarUpdates.find(".ab-label");
      if ($adminBarCounter.length) {
        var count = parseInt($adminBarCounter.text(), 10);
        if (count > 0) {
          count--;
          if (count === 0) {
            $adminBarUpdates.remove();
          } else {
            $adminBarCounter.text(count);
          }
        }
      }
    }
  }

  // Force update all counts across the interface
  function forceUpdateCounts() {
    // Get the current update counts
    var updateCounts = {
      plugins: 0,
      themes: 0,
      wordpress: 0,
      total: 0,
    };

    // Get plugin update count from update badge
    var $pluginUpdates = $(".plugin-count");
    if ($pluginUpdates.length) {
      $pluginUpdates.each(function () {
        var count = parseInt($(this).text(), 10);
        if (!isNaN(count) && count > 0) {
          updateCounts.plugins = count - 1; // Subtract the one we just updated
        }
      });
    }

    // Update all instances of plugin-count
    if (updateCounts.plugins === 0) {
      // If no more plugin updates, remove all plugin update indicators
      $(".plugin-count").closest(".update-plugins").remove();
    } else {
      // Otherwise update the counts
      $(".plugin-count").text(updateCounts.plugins);
    }

    // Update the admin bar
    updateAdminBarCounter();

    // If total count is 0, hide all update indicators
    updateCounts.total =
      updateCounts.plugins + updateCounts.themes + updateCounts.wordpress;
    if (updateCounts.total === 0) {
      // Remove update count from admin bar
      $("#wp-admin-bar-updates").remove();

      // Remove update bubble from Dashboard menu
      $("#menu-dashboard .update-plugins").remove();

      // Remove update bubble from Plugins menu
      $("#menu-plugins .update-plugins").remove();
    }

    // If still on updates page, force redraw any update-related UI
    if (document.body.classList.contains("plugins-php")) {
      // Refresh the screen if needed (in extreme cases)
      if (updateCounts.plugins === 0) {
        // If no more updates, we could reload but that's disruptive
        // Instead remove all update notices
        $(".plugin-update-tr").remove();
        $(".plugins .update").removeClass("update");
      }
    }
  }

  // Update plugin UI after successful update
  function updatePluginUI(plugin, pluginData) {
    // Get plugin slug for DOM manipulation
    var pluginSlug = plugin.replace(/\//g, "-").replace(/\./g, "-");
    if (pluginData && pluginData.plugin_base) {
      pluginSlug = pluginData.plugin_base
        .replace(/\//g, "-")
        .replace(/\./g, "-");
    }

    // If we're on the plugins page
    if (document.body.classList.contains("plugins-php")) {
      // Remove update notification row
      var $pluginRow = $("#" + pluginSlug);
      if ($pluginRow.length) {
        var $updateRow = $pluginRow.next("tr.plugin-update-tr");
        if ($updateRow.length) {
          $updateRow.remove();

          // Update the plugin row to show it's up to date
          $pluginRow.removeClass("update");

          // Update plugin version in the row if possible
          var $versionEl = $pluginRow.find(
            ".plugin-version-author-uri .plugin-version"
          );
          if ($versionEl.length) {
            // Try to extract version from HTML
            var currentText = $versionEl.text();
            var newVersionMatch = currentText.match(/\d+\.\d+\.\d+/);
            if (newVersionMatch) {
              $versionEl.text("Version " + newVersionMatch[0]);
            }
          }

          // Remove update notification count
          var $updateCount = $(".update-plugins .plugin-count");
          var count = parseInt($updateCount.text(), 10);
          if (count > 0) {
            count--;
            if (count === 0) {
              $(".update-plugins").remove();
            } else {
              $updateCount.text(count);
            }
          }
        }
      }
    }

    // If we're on the updates page
    if (document.body.classList.contains("update-core-php")) {
      var $pluginCheckbox = $(
        'input[type="checkbox"][name="checked[]"][value="' + plugin + '"]'
      );
      if ($pluginCheckbox.length) {
        $pluginCheckbox.closest("tr").fadeOut(300, function () {
          $(this).remove();

          // If no more plugins to update, refresh the page
          if ($('input[type="checkbox"][name="checked[]"]').length === 0) {
            setTimeout(function () {
              window.location.reload();
            }, 1000);
          }
        });
      }
    }

    // If we're on the Local Plugin Updater page
    if ($(".lpu-admin-main").length) {
      // Remove the plugin from the updates table
      var $updateTable = $(".lpu-updates-table");
      if ($updateTable.length) {
        $updateTable.find("tr").each(function () {
          var $updateButton = $(this).find(".lpu-update-plugin");
          if ($updateButton.length && $updateButton.data("plugin") === plugin) {
            $(this).fadeOut(300, function () {
              $(this).remove();

              // If this was the last update, show "no updates" message
              if ($updateTable.find("tbody tr:visible").length === 0) {
                $("#lpu-update-results").html(
                  '<p class="lpu-no-updates">No updates available.</p>'
                );
              }
            });
          }
        });
      }
    }

    // Update admin bar counters
    updateAdminBarCounter();

    // Update dashboard widgets if we're on the dashboard
    if (document.body.classList.contains("index-php")) {
      // WordPress update dashboard widget
      var $dashboardUpdates = $("#wp-version-message");
      if ($dashboardUpdates.length) {
        var $pluginUpdates = $dashboardUpdates.find(".plugin-count");
        if ($pluginUpdates.length) {
          var dashCount = parseInt($pluginUpdates.text(), 10);
          if (dashCount > 0) {
            dashCount--;
            if (dashCount === 0) {
              // If no more plugin updates, remove the message
              $pluginUpdates.parent().remove();
            } else {
              $pluginUpdates.text(dashCount);
            }
          }
        }
      }
    }
  }

  // Document ready
  $(document).ready(function () {
    // Add container around auto check and its dependent settings
    const $autoCheckWrapper = $("#lpu_auto_check").closest(
      ".lpu-option-wrapper"
    );
    const $frequencyRow = $(".lpu-frequency-row");
    const $autoUpdateRow = $(".lpu-auto-update-row");

    // Wrap the auto check option and its dependent rows in a container
    $autoCheckWrapper
      .add($frequencyRow)
      .add($autoUpdateRow)
      .wrapAll('<div class="lpu-auto-check-container"></div>');

    // Check if the lpu-auto-check-container exists (in case the HTML structure changed)
    if ($(".lpu-auto-check-container").length) {
      // Ensure the hidden class is properly applied initially
      const isAutoCheckEnabled = $("#lpu_auto_check").is(":checked");
      updateDependentFields(isAutoCheckEnabled, true);

      // Toggle switch click handler with debounce
      let timeoutId;
      $('.lpu-toggle-switch input[type="checkbox"]').on("change", function () {
        const $toggle = $(this);

        // If this is the auto check toggle, show/hide dependent fields
        if ($toggle.attr("id") === "lpu_auto_check") {
          // Clear any pending animations
          clearTimeout(timeoutId);

          // Wait a tiny bit for the checkbox state to fully update
          timeoutId = setTimeout(() => {
            updateDependentFields($toggle.is(":checked"), false);
          }, 50);
        }
      });
    }

    // Function to update dependent fields with animation
    function updateDependentFields(isAutoCheckEnabled, isInitial) {
      if (isAutoCheckEnabled) {
        // Show rows with animation
        $frequencyRow.removeClass("hidden");
        $autoUpdateRow.removeClass("hidden");

        // Force repaint to ensure smooth animation
        if (!isInitial) {
          $frequencyRow[0].offsetHeight;
          $autoUpdateRow[0].offsetHeight;
        }
      } else {
        // Hide rows with animation
        $frequencyRow.addClass("hidden");
        $autoUpdateRow.addClass("hidden");
      }
    }

    // Copy path button handler
    $("#lpu-copy-path").on("click", function (e) {
      e.preventDefault();
      var path = $("#lpu_repo_path").val();

      // Use modern Clipboard API when available
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard
          .writeText(path)
          .then(function () {
            showCopyTooltip("Path copied to clipboard!");
          })
          .catch(function () {
            showCopyTooltip("Failed to copy path. Please copy manually.");
          });
      } else {
        // Fallback for older browsers
        var tempInput = $("<textarea>");
        $("body").append(tempInput);
        tempInput.val(path).select();

        var success = document.execCommand("copy");
        tempInput.remove();

        if (success) {
          showCopyTooltip("Path copied to clipboard!");
        } else {
          showCopyTooltip("Failed to copy path. Please copy manually.");
        }
      }
    });

    // Function to show a copy tooltip
    function showCopyTooltip(message) {
      // Remove any existing tooltips
      $(".lpu-path-tooltip").remove();

      // Create and append the tooltip
      var $tooltip = $('<div class="lpu-path-tooltip"></div>').text(message);
      $("#lpu-copy-path").after($tooltip);

      // Position the tooltip
      var btnPos = $("#lpu-copy-path").position();
      var btnWidth = $("#lpu-copy-path").outerWidth();
      var btnHeight = $("#lpu-copy-path").outerHeight();

      $tooltip.css({
        top: btnPos.top + btnHeight + 5 + "px",
        left: btnPos.left + btnWidth / 2 - $tooltip.outerWidth() / 2 + "px",
        display: "block",
      });

      // Hide the tooltip after 2 seconds
      setTimeout(function () {
        $tooltip.fadeOut(300, function () {
          $(this).remove();
        });
      }, 2000);
    }

    // Check Updates button handler
    $("#lpu-check-updates").on("click", function () {
      var $button = $(this);
      var $results = $("#lpu-update-results");

      // Disable the button and show loading state
      $button
        .prop("disabled", true)
        .html('<span class="lpu-spinner"></span> ' + LPU.checking);

      // Add loading indicator to results area
      $results.html(
        '<div class="lpu-loading"><span class="lpu-spinner"></span> ' +
          LPU.checking +
          "...</div>"
      );

      // Make the AJAX request
      $.ajax({
        url: LPU.ajax_url,
        type: "POST",
        data: {
          action: "lpu_check_updates",
          nonce: LPU.nonce,
        },
        success: function (response) {
          if (response.success) {
            // Use the HTML directly from the server or create our own
            if (response.data.html) {
              $results.html(response.data.html);
            } else if (response.data.count > 0) {
              // Generate the HTML for the updates table
              var html = '<table class="widefat striped lpu-updates-table">';
              html += "<thead><tr>";
              html += "<th>" + "Plugin" + "</th>";
              html += "<th>" + "Current Version" + "</th>";
              html += "<th>" + "New Version" + "</th>";
              html += "<th>" + "Actions" + "</th>";
              html += "</tr></thead><tbody>";

              $.each(response.data.updates, function (index, update) {
                html += "<tr>";
                html += '<td class="plugin-name">' + update.name + "</td>";
                html +=
                  '<td class="current-version">' +
                  update.current_version +
                  "</td>";
                html +=
                  '<td class="new-version">' + update.new_version + "</td>";
                html +=
                  '<td class="plugin-actions"><button type="button" class="button button-primary lpu-update-plugin" data-plugin="' +
                  update.plugin +
                  '">Update</button></td>';
                html += "</tr>";
              });

              html += "</tbody></table>";
              $results.html(html);
            } else {
              // No updates available
              var html = '<div class="lpu-no-updates-container">';
              html += '<span class="dashicons dashicons-yes-alt"></span>';
              html +=
                '<p class="lpu-no-updates">No updates available at this time.</p>';
              html += "</div>";
              $results.html(html);
            }

            // Always update the last checked text to "just now"
            var $lastChecked = $(".lpu-last-checked");
            if ($lastChecked.length) {
              $lastChecked.text("Last checked: just now");
            } else {
              // If no last checked element exists, create one
              $(
                '<span class="lpu-last-checked">Last checked: just now</span>'
              ).insertAfter($button);
            }

            // Show a success message at the top of the page
            $(
              '<div class="notice notice-success is-dismissible"><p>' +
                "Update check completed successfully." +
                "</p></div>"
            ).insertAfter(".wp-header-end");

            // Automatically dismiss the success message after 3 seconds
            setTimeout(function () {
              $(".notice-success").fadeOut(300, function () {
                $(this).remove();
              });
            }, 3000);

            // Reset the button state
            $button.prop("disabled", false).text("Check for Updates");
          } else {
            $results.html(
              '<div class="lpu-no-updates-container error">' +
                '<span class="dashicons dashicons-warning"></span>' +
                '<p class="lpu-notice lpu-notice-error">' +
                (response.data.message || LPU.error) +
                "</p>" +
                "</div>"
            );
            $button.prop("disabled", false).text("Check for Updates");
          }
        },
        error: function () {
          $results.html(
            '<div class="lpu-no-updates-container error">' +
              '<span class="dashicons dashicons-warning"></span>' +
              '<p class="lpu-notice lpu-notice-error">' +
              LPU.error +
              "</p>" +
              "</div>"
          );
          $button.prop("disabled", false).text("Check for Updates");
        },
      });
    });

    // Plugin update handler (delegated)
    $(document).on("click", ".lpu-update-plugin", function () {
      var $button = $(this);
      var pluginFile = $button.data("plugin");
      var $row = $button.closest("tr");

      // Disable the button and show loading state
      $button
        .prop("disabled", true)
        .html('<span class="lpu-spinner"></span> ' + LPU.updating);

      // Make the AJAX request
      $.ajax({
        url: LPU.ajax_url,
        type: "POST",
        data: {
          action: "lpu_update_plugin",
          plugin: pluginFile,
          nonce: LPU.nonce,
        },
        success: function (response) {
          if (response.success) {
            // Remove the row from the updates table
            $row.fadeOut(300, function () {
              $(this).remove();

              // If no more updates, show "no updates" message
              if ($(".lpu-updates-table tbody tr").length === 0) {
                $("#lpu-update-results").html(
                  '<p class="lpu-no-updates">No updates available.</p>'
                );
              }
            });

            // After successful update, force WordPress to clear its update cache
            forceClearUpdateCache(pluginFile);
          } else {
            $button.after(
              '<span class="lpu-warning">' +
                (response.data.message || LPU.error) +
                "</span>"
            );
          }
        },
        error: function () {
          $button.after('<span class="lpu-warning">' + LPU.error + "</span>");
        },
        complete: function () {
          // Reset the button state
          $button.prop("disabled", false).text("Update");
        },
      });
    });

    // Function to force clearing the WordPress update cache after a plugin update
    function forceClearUpdateCache(pluginFile) {
      // Run the sync updates function to ensure WordPress cache is consistent
      $.ajax({
        url: LPU.ajax_url,
        type: "POST",
        data: {
          action: "lpu_sync_updates",
          nonce: LPU.nonce,
        },
        success: function (response) {
          if (response.success) {
            // Make WordPress check for updates again on the main site
            if (response.data.refreshNeeded) {
              // If we need to refresh the page to show correct update status, wait 2 seconds and refresh
              setTimeout(function () {
                window.location.reload();
              }, 2000);
            }
          }
        },
      });
    }
  });
})(jQuery);
