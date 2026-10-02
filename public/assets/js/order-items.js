(function () {
    // SO/PO create form: instead of N repeatable "product / qty / price"
    // rows, there's a single picker (one product search, one qty, one
    // price) - clicking "Tambah" pushes it into a cart-style gallery below
    // and resets the picker for the next product, rather than the old
    // "add another row" pattern.
    var picker = document.querySelector('[data-item-picker]');
    var cartContainer = document.querySelector('[data-cart-items]');
    var cardTemplate = document.querySelector('[data-cart-card-template]');
    var addButton = document.querySelector('[data-add-to-cart]');
    var errorEl = document.querySelector('[data-item-picker-error]');
    var emptyState = document.querySelector('[data-cart-empty]');

    if (!picker || !cartContainer || !cardTemplate || !addButton) {
        return;
    }

    // SO submits items[i][qty]/[sell_price], PO submits items[i][qty_ordered]/
    // [cost_price] - the picker's field names come from these attributes so
    // this one script drives both forms unchanged.
    var qtyField = picker.dataset.qtyField || 'qty';
    var priceField = picker.dataset.priceField || 'sell_price';

    var pickerDropdown = picker.querySelector('[data-async-dropdown="products"]');
    var pickerValue = pickerDropdown.querySelector('[data-async-value]');
    var pickerLabel = pickerDropdown.querySelector('[data-async-input]');
    var pickerQty = picker.querySelector('[data-item-picker-qty]');
    var pickerPrice = picker.querySelector('[data-item-picker-price]');

    var counter = 0;
    var cartProductIds = {};

    function showError(message) {
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function clearError() {
        errorEl.hidden = true;
        errorEl.textContent = '';
    }

    function updateEmptyState() {
        var hasItems = cartContainer.querySelectorAll('[data-cart-card]').length > 0;
        if (emptyState) {
            emptyState.hidden = hasItems;
        }
    }

    function resetPicker() {
        pickerValue.value = '';
        pickerLabel.value = '';
        delete pickerDropdown.dataset.selectedImage;
        pickerQty.value = '1';
        pickerPrice.value = '';
    }

    function formatRupiah(value) {
        var rounded = Math.round(value * 100) / 100;
        return 'Rp ' + rounded.toLocaleString('id-ID');
    }

    function addToCart() {
        clearError();

        var productId = pickerValue.value;
        var label = pickerLabel.value.trim();
        var qty = Number.parseInt(pickerQty.value, 10);
        var price = Number.parseFloat(pickerPrice.value);

        if (!productId) {
            showError('Pilih produk terlebih dahulu.');
            return;
        }
        if (!qty || qty <= 0) {
            showError('Qty harus bilangan bulat lebih dari 0.');
            return;
        }
        if (Number.isNaN(price) || price < 0) {
            showError('Harga harus diisi dengan angka minimal 0.');
            return;
        }
        if (cartProductIds[productId]) {
            showError('Produk ini sudah ditambahkan.');
            return;
        }

        var index = counter;
        counter += 1;
        cartProductIds[productId] = true;

        var image = pickerDropdown.dataset.selectedImage || '';

        var node = cardTemplate.content.firstElementChild.cloneNode(true);
        node.dataset.productId = productId;

        var img = node.querySelector('[data-cart-card-img]');
        var emptyIcon = node.querySelector('[data-cart-card-empty-icon]');
        if (image) {
            img.src = image;
            img.hidden = false;
            emptyIcon.hidden = true;
        } else {
            img.hidden = true;
            emptyIcon.hidden = false;
        }

        node.querySelector('[data-cart-card-label]').textContent = label;
        node.querySelector('[data-cart-card-qty]').textContent = String(qty);
        node.querySelector('[data-cart-card-price]').textContent = formatRupiah(price);
        node.querySelector('[data-cart-card-subtotal]').textContent = formatRupiah(qty * price);

        var productInput = document.createElement('input');
        productInput.type = 'hidden';
        productInput.name = 'items[' + index + '][product_id]';
        productInput.value = productId;
        productInput.dataset.cartProductId = productId;
        node.appendChild(productInput);

        var qtyInput = document.createElement('input');
        qtyInput.type = 'hidden';
        qtyInput.name = 'items[' + index + '][' + qtyField + ']';
        qtyInput.value = String(qty);
        node.appendChild(qtyInput);

        var priceInput = document.createElement('input');
        priceInput.type = 'hidden';
        priceInput.name = 'items[' + index + '][' + priceField + ']';
        priceInput.value = String(price);
        node.appendChild(priceInput);

        cartContainer.appendChild(node);
        updateEmptyState();
        resetPicker();
    }

    addButton.addEventListener('click', addToCart);

    cartContainer.addEventListener('click', function (event) {
        var removeBtn = event.target.closest('[data-remove-cart-item]');
        if (!removeBtn) {
            return;
        }
        var card = removeBtn.closest('[data-cart-card]');
        if (!card) {
            return;
        }
        var productId = card.dataset.productId;
        delete cartProductIds[productId];
        card.remove();
        updateEmptyState();
    });

    updateEmptyState();
})();
