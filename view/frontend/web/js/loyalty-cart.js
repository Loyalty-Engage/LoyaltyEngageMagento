require(['jquery', 'Magento_Customer/js/customer-data'], function ($, customerData) {
    $(document).ready(function () {
        customerData.get('customer').subscribe(function(customer) {
            if (customer && customer.id) {
                var customerId = customer.id;
                console.log('customer id:', customerId);
                $('#loyalty-btn').on('click', function () {
                    const sku = $('#product-sku').attr('data-product-sku');

                    if (!customerId || !sku) {
                        alert('Customer ID and SKU are required.');
                        return;
                    }

                    const endpointUrl = `/rest/V1/loyalty/shop/${customerId}/cart/add`;
                    console.log('Endpoint URL:', endpointUrl);

                    $.ajax({
                        url: endpointUrl,
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ sku }),
                        success: function (response) {
                            if (response.success) {
                                alert(response.message);
                            } else {
                                alert(`Error: ${response.message}`);
                            }
                        },
                        error: function (xhr) {
                            alert(`Request failed: ${xhr.responseText}`);
                        },
                    });
                });
                $('#loyalty-discount-btn').on('click', function () {
                    const discount = parseFloat($(this).attr('data-discount'));
                    const sku = $('#discount-product-sku').attr('data-sku');

                    if (!customerId || !sku || isNaN(discount)) {
                        alert('Customer ID, SKU, and discount are required.');
                        return;
                    }

                    const endpointUrl = `/rest/V1/loyalty/discount/${customerId}/claim-after-cart`;

                    $.ajax({
                        url: endpointUrl,
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ discount, sku }),
                        success: function (response) {
                            alert(response.message);
                        },
                        error: function (xhr) {
                            alert(`Request failed: ${xhr.responseText}`);
                        }
                    });
                });

            } else {
                console.log('Customer is not logged in');
                alert('Customer is not logged in.');
            }
        });
    });
});
