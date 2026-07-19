(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.pmsrNestedDropdown = {
    attach: function (context, settings) {
      // Handle nested dropdowns on hover
      const dropdownItems = once('pmsr-nested-dropdown', '.dropdown-menu .dropdown-item.dropdown, .dropdown-menu .dropdown-item.menu-item--expanded', context);
      
      dropdownItems.forEach(function(item) {
        var $dropdownItem = $(item);
        var $nestedMenu = $dropdownItem.find(' > .dropdown-menu');
        
        if ($nestedMenu.length === 0) {
          return;
        }
        
        // Show nested dropdown on hover
        $dropdownItem.on('mouseenter', function() {
          $nestedMenu.addClass('show');
        });
        
        // Hide nested dropdown when mouse leaves
        $dropdownItem.on('mouseleave', function() {
          $nestedMenu.removeClass('show');
        });
        
        // Also handle click for touch devices
        $dropdownItem.find(' > span, > a').on('click', function(e) {
          if ($nestedMenu.hasClass('show')) {
            $nestedMenu.removeClass('show');
          } else {
            // Hide other nested dropdowns first
            $('.dropdown-menu .dropdown-menu.show').removeClass('show');
            $nestedMenu.addClass('show');
          }
          e.preventDefault();
          e.stopPropagation();
        });
      });
    }
  };

})(jQuery, Drupal, once);
