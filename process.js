define(['jquery', 'fab/fabrik'], function (jQuery, Fabrik) {
    'use strict';
    var FabrikProcess = new Class({

        Implements: [Events],

        initialize: function (options) {
            this.options = jQuery.extend(this.options, options);

            if (this.options.urlAdmin) {
                var button_group, button;
                button = document.createElement('button');
                button.setAttribute('type', 'button');
                button.setAttribute('class', 'btn ' + this.options.button_style + ' button');
                button.onclick = () => {
                    window.open(this.options.urlAdmin, '_blank');
                };
                button.innerHTML = this.options.button_label;

                button_group = document.getElementsByClassName('form-actions')[0];
                button_group = button_group.getElementsByClassName('btn-group')[0];

                if (this.options.view === 'form') {
                    button_group.appendChild(button);
                } else if (this.options.view === 'details') {
                    button_group.insertBefore(button, button_group.firstChild);
                }
            }
        }
    });

    return FabrikProcess;
});