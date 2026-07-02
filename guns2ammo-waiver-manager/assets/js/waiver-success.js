// File: assets/js/waiver-success.js
document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('waiver_signed') === 'true') {
        const popup = document.createElement('div');
        popup.innerHTML = `
            <div id="waiver-success-popup" style="
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0,0,0,0.6);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            ">
                <div style="
                    background: white;
                    padding: 2rem;
                    border-radius: 10px;
                    text-align: center;
                    max-width: 400px;
                    box-shadow: 0 0 15px rgba(0,0,0,0.3);
                ">
                    <h2>✅ Waiver Signed Successfully!</h2>
                    <p>Thank you for signing. You're now good to go.</p>
                    <button id="waiver-success-ok" style="
                        margin-top: 1rem;
                        padding: 0.5rem 1rem;
                        background: #2ecc71;
                        color: white;
                        border: none;
                        border-radius: 5px;
                        cursor: pointer;
                        font-size: 16px;
                    ">Done</button>
                </div>
            </div>
        `;
        document.body.appendChild(popup);

        document.getElementById('waiver-success-ok').addEventListener('click', function () {
            // Remove popup
            document.getElementById('waiver-success-popup').remove();

            // Remove query param from URL without reload
            const cleanUrl = window.location.href.split('?')[0];
            window.history.replaceState({}, document.title, cleanUrl);
        });
    }
});
