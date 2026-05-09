import template from './sw-order-detail-general.html.twig'

const { Component } = Shopware

// Shopware 6.7 split the order detail page into separate tab components
// (sw-order-detail-general, sw-order-detail-details, sw-order-detail-documents).
// We extend the General tab to append two cards: one for actions, one for history.
Component.override('sw-order-detail-general', {
  template,
})
