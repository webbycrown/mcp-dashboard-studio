/**
 * MCP Dashboard Studio - Bulk Actions Manager
 * Handles checkbox selection, UUID collection, confirmation modal, and form submission
 */

(function () {
    "use strict";

    // Configuration
    const config = {
        formId: "mgr-bulk-form",
        bulkBarId: "mgr-bulk-bar",
        bulkCountId: "mgr-bulk-count",
        selectAllId: "mgr-select-all",
        rowCheckClass: "mgr-row-check",
        bulkClearId: "mgr-bulk-clear",
        bulkActionsContainerId: "mgr-bulk-actions",
    };

    // State
    const state = {
        selectedUuids: new Set(),
    };

    // Initialize when DOM is ready
    document.addEventListener("DOMContentLoaded", function () {
        initializeBulkActions();
    });

    /**
     * Initialize bulk action handlers
     */
    function initializeBulkActions() {
        const form = document.getElementById(config.formId);
        const selectAllCheckbox = document.getElementById(config.selectAllId);
        const rowCheckboxes = document.querySelectorAll(
            "." + config.rowCheckClass,
        );
        const clearBtn = document.getElementById(config.bulkClearId);
        const actionButtons = form.querySelectorAll('button[name="action"]');

        // Select All handler
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener("change", function () {
                rowCheckboxes.forEach((checkbox) => {
                    checkbox.checked = this.checked;
                    updateSelection(checkbox);
                });
                updateBulkBar();
            });
        }

        // Individual row checkbox handlers
        rowCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener("change", function () {
                updateSelection(this);
                updateSelectAllState();
                updateBulkBar();
            });
        });

        const bulkSelect = document.getElementById("bulk_select");

        if (bulkSelect) {
            bulkSelect.addEventListener("change", function () {
                const action = this.value;

                if (!action) {
                    return;
                }

                if (state.selectedUuids.size === 0) {
                    showAlert(
                        "warning",
                        "Please select at least one dashboard.",
                    );

                    this.value = "";
                    return;
                }

                showConfirmationModal(action, state.selectedUuids.size);

                this.value = "";
            });
        }

        // Clear selection handler
        if (clearBtn) {
            clearBtn.addEventListener("click", function (e) {
                e.preventDefault();
                state.selectedUuids.clear();
                rowCheckboxes.forEach((checkbox) => {
                    checkbox.checked = false;
                });
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
                updateBulkBar();
            });
        }

        // Action button handlers
        actionButtons.forEach((btn) => {
            btn.addEventListener("click", function (e) {
                e.preventDefault();
                const action = this.value;
                showConfirmationModal(action, state.selectedUuids.size);
            });
        });
    }

    /**
     * Update selection state for a checkbox
     */
    function updateSelection(checkbox) {
        const uuid = checkbox.dataset.uuid;
        if (checkbox.checked) {
            state.selectedUuids.add(uuid);
        } else {
            state.selectedUuids.delete(uuid);
        }
    }

    /**
     * Update select-all checkbox state
     */
    function updateSelectAllState() {
        const selectAllCheckbox = document.getElementById(config.selectAllId);
        const rowCheckboxes = document.querySelectorAll(
            "." + config.rowCheckClass,
        );

        if (selectAllCheckbox) {
            const allChecked = Array.from(rowCheckboxes).every(
                (cb) => cb.checked,
            );
            selectAllCheckbox.checked = allChecked;
        }
    }

    /**
     * Update bulk action bar visibility and count
     */
    function updateBulkBar() {
        const bulkBar = document.getElementById(config.bulkBarId);
        const countElement = document.getElementById(config.bulkCountId);
        const count = state.selectedUuids.size;

        if (bulkBar) {
            if (count > 0) {
                bulkBar.classList.add("active");
                if (countElement) {
                    countElement.textContent = count + " selected";
                }
            } else {
                bulkBar.classList.remove("active");
                if (countElement) {
                    countElement.textContent = "0 selected";
                }
            }
        }
    }

    /**
     * Show confirmation modal
     */
    function showConfirmationModal(action, count) {
        if (count === 0) {
            showAlert("warning", "Please select at least one dashboard.");
            return;
        }

        // Validate UUIDs exist in DB before showing modal
        validateSelectedUuids(function (valid, message) {
            if (!valid) {
                showAlert(
                    "error",
                    message ||
                        "One or more selected dashboards were not found.",
                );
                return;
            }

            // Create and display modal
            const modal = createConfirmationModal(action, count);
            document.body.appendChild(modal);

            // Handle modal actions
            const confirmBtn = modal.querySelector(".modal-confirm");
            const cancelBtn = modal.querySelector(".modal-cancel");
            const backdrop = modal.querySelector(".modal-backdrop");

            confirmBtn.addEventListener("click", function () {
                submitBulkAction(action);
                document.body.removeChild(modal);
            });

            cancelBtn.addEventListener("click", function () {
                document.body.removeChild(modal);
            });

            backdrop.addEventListener("click", function () {
                document.body.removeChild(modal);
            });
        });
    }

    /**
     * Validate selected UUIDs on backend
     */
    function validateSelectedUuids(callback) {
        const csrfToken = document.querySelector(
            'meta[name="csrf-token"]',
        )?.content;
        const uuids = Array.from(state.selectedUuids);

        // Quick check: ensure we have UUIDs
        if (uuids.length === 0) {
            callback(false, "No dashboards selected.");
            return;
        }

        // Make validation request
        fetch(
            (window.mcpManagerRoutes && window.mcpManagerRoutes.validateBulk)
                || (window.location.origin + "/mcp-manager/dashboards/validate-bulk"),
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                },
                body: JSON.stringify({ uuids: uuids }),
            },
        )
            .then((response) => response.json())
            .then((data) => {
                if (data.valid) {
                    callback(true);
                } else {
                    callback(false, data.message || "Validation failed.");
                }
            })
            .catch((error) => {
                console.error("Validation error:", error);
                // If validation endpoint doesn't exist, proceed anyway
                callback(true);
            });
    }

    /**
     * Create confirmation modal HTML
     */
    function createConfirmationModal(action, count) {
        const actionLabels = {
            make_public: "Make Public",
            make_private: "Make Private",
            delete: "Delete",
        };
        const actionMessages = {
            make_public: `Make ${count} dashboard(s) public?`,
            make_private: `Make ${count} dashboard(s) private?`,
            delete: `Move ${count} dashboard(s) to trash?`,
        };

        const modal = document.createElement("div");
        modal.className = "bulk-action-modal";
        modal.innerHTML = `
      <div class="modal-backdrop"></div>
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title">Confirm Bulk Action</h2>
          <button type="button" class="modal-close" aria-label="Close">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
        <div class="modal-body">
          <p class="modal-message">${actionMessages[action] || "Proceed with this action?"}</p>
          <div class="modal-stats">
            <div class="stat">
              <span class="stat-label">Selected:</span>
              <span class="stat-value">${count}</span>
            </div>
            <div class="stat">
              <span class="stat-label">Action:</span>
              <span class="stat-value">${actionLabels[action] || action}</span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="modal-cancel btn btn-outline-secondary">Cancel</button>
          <button type="button" class="modal-confirm btn btn-indigo">Confirm</button>
        </div>
      </div>
    `;

        // Close button handler
        const closeBtn = modal.querySelector(".modal-close");
        closeBtn.addEventListener("click", function () {
            document.body.removeChild(modal);
        });

        return modal;
    }

    /**
     * Submit bulk action form
     */
    function submitBulkAction(action) {
        const form = document.getElementById(config.formId);

        // Clear existing hidden inputs
        const existingInputs = form.querySelectorAll('input[name="uuids[]"]');
        existingInputs.forEach((input) => input.remove());

        // Add UUIDs as hidden form fields
        state.selectedUuids.forEach((uuid) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "uuids[]";
            input.value = uuid;
            form.appendChild(input);
        });

        // Add action as hidden input
        let actionInput = form.querySelector('input[name="action"]');
        if (!actionInput) {
            actionInput = document.createElement("input");
            actionInput.type = "hidden";
            actionInput.name = "action";
            form.appendChild(actionInput);
        }
        actionInput.value = action;

        // Submit form
        form.submit();
    }

    /**
     * Show alert notification
     */
    // function showAlert(type, message) {
    //     const alertDiv = document.createElement("div");
    //     alertDiv.className = `alert alert-${type}`;
    //     alertDiv.style.cssText = `
    //   position: fixed;
    //   top: 20px;
    //   right: 20px;
    //   z-index: 9999;
    //   min-width: 300px;
    //   animation: slideInRight 0.3s ease;
    // `;
    //     alertDiv.innerHTML = `
    //   <div style="display: flex; align-items: center; gap: 10px;">
    //     <i class="bi ${
    //         type === "success"
    //             ? "bi-check-circle-fill"
    //             : type === "error"
    //               ? "bi-exclamation-triangle-fill"
    //               : "bi-info-circle-fill"
    //     }"></i>
    //     <span>${message}</span>
    //   </div>
    // `;

    //     document.body.appendChild(alertDiv);

    //     // Auto-remove after 5 seconds
    //     setTimeout(() => {
    //         if (alertDiv.parentElement) {
    //             alertDiv.remove();
    //         }
    //     }, 5000);
    // }

    function showAlert(type, message) {
        const toast = document.createElement("div");

        toast.className = `mgr-toast ${type}`;

        toast.innerHTML = `
        <span>${message}</span>
    `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 4000);
    }

    // Expose for testing
    window.mgrBulkActions = {
        state,
        showConfirmationModal,
        submitBulkAction,
    };
})();
