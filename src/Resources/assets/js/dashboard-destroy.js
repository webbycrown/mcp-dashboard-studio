document.addEventListener("DOMContentLoaded", function () {
    const deleteButtons = document.querySelectorAll(".row-action-delete");

    deleteButtons.forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();

            const url = button.dataset.url;
            const dashboardName =
                button.closest("tr")?.querySelector(".dashboard-name")
                    ?.innerText || "this dashboard";

            const modal = document.createElement("div");
            modal.className = "bulk-action-modal";

            modal.innerHTML = `
                <div class="modal-backdrop"></div>

                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Confirm Delete</h2>
                        <button type="button" class="modal-close" aria-label="Close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="modal-body">
                        <p class="modal-message">
                            Delete "${dashboardName}"? This action cannot be undone.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="modal-cancel btn btn-outline-secondary">
                            Cancel
                        </button>

                        <button type="button" class="modal-confirm btn btn-danger">
                            Delete
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            const removeModal = () => {
                if (modal.parentElement) {
                    modal.parentElement.removeChild(modal);
                }
            };

            modal
                .querySelector(".modal-close")
                .addEventListener("click", removeModal);

            modal
                .querySelector(".modal-cancel")
                .addEventListener("click", removeModal);

            modal
                .querySelector(".modal-backdrop")
                .addEventListener("click", removeModal);

            modal
                .querySelector(".modal-confirm")
                .addEventListener("click", function () {
                    let form = document.createElement("form");

                    form.method = "POST";
                    form.action = url;

                    form.innerHTML = `
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <input type="hidden" name="_method" value="DELETE">
                    `;

                    document.body.appendChild(form);

                    removeModal();

                    form.submit();
                });
        });
    });
});
