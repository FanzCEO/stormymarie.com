/**
 * STORMY MARIE - API Module
 * Handles all API communication for the site
 */

const StormyAPI = (function() {
    'use strict';

    const API_BASE = window.location.hostname === 'localhost'
        ? ''
        : '';

    function getToken() {
        return localStorage.getItem('adminToken') || '';
    }

    function authHeaders(extra) {
        const headers = {};
        const token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }
        if (extra) {
            Object.assign(headers, extra);
        }
        return headers;
    }

    async function request(url, options) {
        try {
            const res = await fetch(API_BASE + url, options || {});
            return await res.json();
        } catch (err) {
            console.warn('API request failed:', url, err.message);
            return { success: false, error: err.message };
        }
    }

    // Gallery
    async function getGallery() {
        // Try PHP endpoint first, fall back to static JSON
        let data = await request('/api/upload.php?action=list-gallery');
        if (data && data.success) return data;
        data = await request('/api/gallery.json');
        return data;
    }

    // Settings
    async function getSettings() {
        return request('/api/upload.php?action=get-settings');
    }

    async function saveSettings(settings) {
        return request('/api/upload.php?action=save-settings', {
            method: 'POST',
            headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(settings)
        });
    }

    // Auth
    async function login(username, password) {
        return request('/api/upload.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });
    }

    // Upload
    async function uploadFile(file, action) {
        const formData = new FormData();
        formData.append('file', file);
        return request('/api/upload.php?action=' + action, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + getToken() },
            body: formData
        });
    }

    async function uploadGalleryFiles(files) {
        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }
        return request('/api/upload.php?action=upload-gallery', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + getToken() },
            body: formData
        });
    }

    async function deleteGalleryImage(filename) {
        return request('/api/upload.php?action=delete-gallery', {
            method: 'POST',
            headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ filename })
        });
    }

    // Contact form
    async function submitContact(formData) {
        // For static hosting, mailto fallback
        const email = 'contact@stormymarie.com';
        const subject = encodeURIComponent('Contact from ' + (formData.name || 'Website'));
        const body = encodeURIComponent(
            'Name: ' + formData.name + '\n' +
            'Email: ' + formData.email + '\n' +
            'Subject: ' + (formData.subject || '') + '\n\n' +
            formData.message
        );
        window.location.href = 'mailto:' + email + '?subject=' + subject + '&body=' + body;
        return { success: true };
    }

    // Booking form
    async function submitBooking(formData) {
        const email = 'booking@stormymarie.com';
        const subject = encodeURIComponent('Booking Request from ' + (formData.name || 'Website'));
        const body = encodeURIComponent(
            'Name: ' + formData.name + '\n' +
            'Email: ' + formData.email + '\n' +
            'Date: ' + (formData.date || '') + '\n' +
            'Service: ' + (formData.service || '') + '\n\n' +
            (formData.message || formData.details || '')
        );
        window.location.href = 'mailto:' + email + '?subject=' + subject + '&body=' + body;
        return { success: true };
    }

    return {
        getGallery: getGallery,
        getSettings: getSettings,
        saveSettings: saveSettings,
        login: login,
        uploadFile: uploadFile,
        uploadGalleryFiles: uploadGalleryFiles,
        deleteGalleryImage: deleteGalleryImage,
        submitContact: submitContact,
        submitBooking: submitBooking,
        getToken: getToken,
        authHeaders: authHeaders
    };
})();
