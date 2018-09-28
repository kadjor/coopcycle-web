import React from 'react';
import { render } from 'react-dom';
import AddressPicker from "../components/AddressPicker.jsx";

let initialGeohash = window.AppData.geohash,
    address = localStorage.getItem('search_address')

$('#restaurant-search-form').find('input[name=geohash]').val(initialGeohash);

function onPlaceChange (geohash, address) {
  if (geohash != initialGeohash) {
    localStorage.setItem('search_geohash', geohash);
    localStorage.setItem('search_address', address);

    window._paq.push(['trackEvent', 'RestaurantList', 'searchAddress', address]);

    $('#restaurant-search-form').find('input[name=geohash]').val(geohash);
    $('#restaurant-search-form').submit();
  }
}

window.initMap = () => {
  render(<AddressPicker
    geohash={ initialGeohash }
    address={ address }
    onPlaceChange={ onPlaceChange } />,
  document.getElementById('address-search'));
}
