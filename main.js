// Fixed main.js - Sistem Booking Room
// Version 2.1 - Bug Fixes

// Global variables
let loginModal;
let bookingModal;
let confirmationModal;
let checkoutModal;
let currentCheckoutBookingId = null;
let eventDetailModal;
let dayDetailModal;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeModals();
    initializeEventListeners();
    initializeForms();
    updateBookingRangeInfo();
    initializeLoginSystem();
});

// Initialize Bootstrap modals
function initializeModals() {
    try {
        if (document.getElementById('loginModal')) {
            loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
        }
        if (document.getElementById('bookingModal')) {
            bookingModal = new bootstrap.Modal(document.getElementById('bookingModal'));
        }
        if (document.getElementById('checkoutModal')) {
            checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
        }
        if (document.getElementById('confirmationModal')) {
            confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        }
        if (document.getElementById('eventDetailModal')) {
            eventDetailModal = new bootstrap.Modal(document.getElementById('eventDetailModal'));
        }
        if (document.getElementById('dayDetailModal')) {
            dayDetailModal = new bootstrap.Modal(document.getElementById('dayDetailModal'));
        }
    } catch (error) {
        console.error('Error initializing modals:', error);
    }
}


// Helper function to show alerts
function showAlert(message, type = 'info', autoHide = true) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show fixed-top mx-auto mt-3`;
    alertDiv.style.cssText = `
        max-width: 500px;
        z-index: 9999;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
    `;
    
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    if (autoHide) {
        setTimeout(() => {
            try {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            } catch (e) {
                alertDiv.remove();
            }
        }, 5000);
    }
}

// FIXED: Simplified booking function
function bookTimeSlot(date, time, roomId) {
    console.log('🎯 Book time slot called:', { date, time, roomId });
    
    // Check if user is logged in via body class or session
    const isLoggedIn = document.body.classList.contains('logged-in');
    
    if (!isLoggedIn) {
        console.log('🔐 User not logged in, storing booking and showing login');
        
        // Store booking data
        sessionStorage.setItem('pendingBooking', JSON.stringify({
            date: date,
            time: time,
            roomId: roomId
        }));
        
        // Show login modal
        if (loginModal) {
            loginModal.show();
        } else {
            const modal = new bootstrap.Modal(document.getElementById('loginModal'));
            modal.show();
        }
    } else {
        console.log('✅ User logged in, showing booking form');
        showBookingForm(date, time, roomId);
    }
}

// Initialize event listeners
function initializeEventListeners() {
    
    // Booking form
    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleBookingSubmission(e);
        });
    }
    
    // Checkout confirmation
    const confirmCheckbox = document.getElementById('confirmCheckout');
    const confirmBtn = document.getElementById('confirmCheckoutBtn');
    
    if (confirmCheckbox && confirmBtn) {
        confirmCheckbox.addEventListener('change', function() {
            confirmBtn.disabled = !this.checked;
        });
        
        confirmBtn.addEventListener('click', function() {
            if (currentCheckoutBookingId && confirmCheckbox.checked) {
                processEnhancedCheckout(currentCheckoutBookingId);
            }
        });
    }
    
    // Auto-refresh countdown
    initializeAutoRefresh();
}

// Initialize forms
function initializeForms() {
    // Booking form
    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        // Remove existing listeners to avoid duplicates
        bookingForm.removeEventListener('submit', handleBookingSubmission);
        bookingForm.addEventListener('submit', handleBookingSubmission);
    }
    
    // Handle pending booking after login
    const pendingBooking = sessionStorage.getItem('pendingBooking');
    if (pendingBooking) {
        try {
            const bookingData = JSON.parse(pendingBooking);
            const isLoggedIn = document.body.classList.contains('logged-in');
            
            if (isLoggedIn) {
                setTimeout(() => {
                    showBookingForm(bookingData.date, bookingData.time, bookingData.roomId);
                    sessionStorage.removeItem('pendingBooking');
                }, 1000);
            }
        } catch (e) {
            sessionStorage.removeItem('pendingBooking');
        }
    }
}

// Update function showBookingForm
function showBookingForm(date, time, roomId) {
    if (!bookingModal) {
        console.error('Booking modal not initialized');
        return;
    }
    
    // Set hidden fields
    document.getElementById('booking_date').value = date;
    document.getElementById('room_id').value = roomId;
    
    // Set time fields
    const [hours, minutes] = time.split(':');
    const endHours = parseInt(hours) + 1;
    const endTime = endHours.toString().padStart(2, '0') + ':' + minutes;
    
    document.getElementById('jam_mulai').value = time;
    document.getElementById('jam_selesai').value = endTime;
    
    // Reset form messages
    const errorDiv = document.getElementById('bookingError');
    const successDiv = document.getElementById('bookingSuccess');
    if (errorDiv) errorDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');
    
    // Reset form
    const form = document.getElementById('bookingForm');
    if (form) {
        form.reset();
        // Set the values again after reset
        document.getElementById('booking_date').value = date;
        document.getElementById('room_id').value = roomId;
        document.getElementById('jam_mulai').value = time;
        document.getElementById('jam_selesai').value = endTime;
    }
    
    bookingModal.show();
}

// Enhanced booking submission handler
function handleBookingSubmission(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const errorDiv = document.getElementById('bookingError');
    const successDiv = document.getElementById('bookingSuccess');
    const submitBtn = document.getElementById('submitBookingBtn');
    
    // Reset messages
    errorDiv.classList.add('d-none');
    successDiv.classList.add('d-none');
    
    // Validate time range
    const startTime = document.getElementById('jam_mulai').value;
    const endTime = document.getElementById('jam_selesai').value;
    
    if (!startTime || !endTime) {
        errorDiv.textContent = 'Jam mulai dan jam selesai harus diisi.';
        errorDiv.classList.remove('d-none');
        return;
    }
    
    if (startTime >= endTime) {
        errorDiv.textContent = 'Jam selesai harus lebih dari jam mulai.';
        errorDiv.classList.remove('d-none');
        return;
    }
    
    // Validate other required fields
    const requiredFields = ['nama_acara', 'keterangan', 'nama_penanggungjawab', 'no_penanggungjawab'];
    for (let field of requiredFields) {
        const value = formData.get(field);
        if (!value || value.trim() === '') {
            errorDiv.textContent = `Field ${field.replace('_', ' ')} harus diisi.`;
            errorDiv.classList.remove('d-none');
            return;
        }
    }
    
    // Show loading state
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';
    
    // Log form data for debugging
    console.log('Form data being sent:');
    for (let [key, value] of formData.entries()) {
        console.log(`${key}: ${value}`);
    }
    
    fetch('process-booking.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error(`Expected JSON but got: ${text.substring(0, 200)}...`);
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        
        if (data.success) {
            successDiv.textContent = data.message || 'Peminjaman berhasil disubmit!';
            successDiv.classList.remove('d-none');
            
            // Clear form
            form.reset();
            
            // Redirect after delay
            setTimeout(() => {
                if (bookingModal) {
                    bookingModal.hide();
                }
                
                const redirectUrl = 'index.php?booking_success=1&date=' + 
                    document.getElementById('booking_date').value + 
                    '&room_id=' + document.getElementById('room_id').value;
                window.location.href = redirectUrl;
            }, 2000);
        } else {
            errorDiv.textContent = data.message || 'Gagal memproses peminjaman.';
            errorDiv.classList.remove('d-none');
        }
    })
    .catch(error => {
        console.error('Booking error:', error);
        errorDiv.textContent = 'Terjadi kesalahan: ' + error.message;
        errorDiv.classList.remove('d-none');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

// BOOKING FUNCTIONS
function bookTimeSlot(date, time, roomId) {
    // This function is now in index.php to access PHP variables
    console.log('bookTimeSlot called from main.js');
}

function showBookingForm(date, time, roomId) {
    // Set hidden fields
    document.getElementById('booking_date').value = date;
    document.getElementById('room_id').value = roomId;
    
    // Set time fields
    const [hours, minutes] = time.split(':');
    const endHours = parseInt(hours) + 1;
    const endTime = endHours.toString().padStart(2, '0') + ':' + minutes;
    
    document.getElementById('jam_mulai').value = time;
    document.getElementById('jam_selesai').value = endTime;
    
    // Reset form messages
    const errorDiv = document.getElementById('bookingError');
    const successDiv = document.getElementById('bookingSuccess');
    if (errorDiv) errorDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');
    
    bookingModal.show();
}

// CHECKOUT FUNCTIONS
// ENHANCED CHECKOUT FUNCTIONS
function showCheckoutModal(bookingId) {
    currentCheckoutBookingId = bookingId;
    
    const checkoutModalElement = document.getElementById('checkoutModal');
    if (!checkoutModalElement) {
        showAlert('❌ Modal checkout tidak ditemukan', 'danger');
        return;
    }
    
    const detailsDiv = document.getElementById('checkoutDetails');
    if (detailsDiv) {
        detailsDiv.innerHTML = `
            <div class="d-flex justify-content-center align-items-center py-4">
                <div class="spinner-border text-primary me-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <span>Memuat detail booking...</span>
            </div>
        `;
    }
    
    // Show modal immediately
    const modal = new bootstrap.Modal(checkoutModalElement);
    modal.show();
    
    // Fetch booking details with enhanced information
    fetch(`get_booking_detail.php?id=${bookingId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.booking) {
                displayEnhancedCheckoutDetails(data.booking, data.status_info);
            } else {
                displayCheckoutError(data.message || 'Gagal memuat detail booking');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            displayCheckoutError('Terjadi kesalahan saat memuat detail booking: ' + error.message);
        });
}

function displayEnhancedCheckoutDetails(booking, statusInfo) {
    const detailsDiv = document.getElementById('checkoutDetails');
    if (!detailsDiv) return;
    
    detailsDiv.innerHTML = `
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0">
                    <i class="fas fa-calendar-check me-2"></i>${booking.nama_acara}
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary border-bottom pb-2">Informasi Ruangan</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td><i class="fas fa-door-open me-2 text-primary"></i><strong>Ruangan:</strong></td><td>${booking.nama_ruang}</td></tr>
                            <tr><td><i class="fas fa-building me-2 text-secondary"></i><strong>Gedung:</strong></td><td>${booking.nama_gedung}</td></tr>
                            <tr><td><i class="fas fa-calendar me-2 text-info"></i><strong>Tanggal:</strong></td><td>${booking.formatted_date}</td></tr>
                            <tr><td><i class="fas fa-clock me-2 text-warning"></i><strong>Durasi:</strong></td><td>${booking.duration}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary border-bottom pb-2">Informasi PIC</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td><i class="fas fa-clock me-2 text-success"></i><strong>Waktu:</strong></td><td>${booking.jam_mulai} - ${booking.jam_selesai}</td></tr>
                            <tr><td><i class="fas fa-user me-2 text-info"></i><strong>PIC:</strong></td><td>${booking.nama_penanggungjawab}</td></tr>
                            <tr><td><i class="fas fa-phone me-2 text-success"></i><strong>No. HP:</strong></td><td>${booking.no_penanggungjawab}</td></tr>
                            <tr><td><i class="fas fa-envelope me-2 text-primary"></i><strong>Email:</strong></td><td>${booking.email}</td></tr>
                        </table>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h6 class="text-primary border-bottom pb-2">Keterangan Acara</h6>
                        <p class="text-muted mb-0">${booking.keterangan || 'Tidak ada keterangan'}</p>
                    </div>
                </div>
                
                <div class="alert alert-warning mt-3">
                    <h6 class="alert-heading">
                        <i class="fas fa-exclamation-triangle me-2"></i>Informasi Checkout
                    </h6>
                    <p class="mb-2">Dengan melakukan checkout, Anda mengkonfirmasi bahwa:</p>
                    <ul class="mb-2">
                        <li>✅ Kegiatan sudah selesai</li>
                        <li>✅ Ruangan sudah dibersihkan</li>
                        <li>✅ Semua peralatan sudah dikembalikan</li>
                        <li>✅ Status akan berubah menjadi <strong>SELESAI</strong></li>
                    </ul>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Slot akan tersedia lagi untuk user lain!</strong>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Reset checkbox and button
    const confirmCheckbox = document.getElementById('confirmCheckout');
    const confirmBtn = document.getElementById('confirmCheckoutBtn');
    
    if (confirmCheckbox && confirmBtn) {
        confirmCheckbox.checked = false;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> Ya, Checkout Sekarang';
    }
}

function displayCheckoutError(message) {
    const detailsDiv = document.getElementById('checkoutDetails');
    if (detailsDiv) {
        detailsDiv.innerHTML = `
            <div class="alert alert-danger">
                <h6 class="alert-heading">
                    <i class="fas fa-exclamation-triangle me-2"></i>Error
                </h6>
                <p class="mb-0">${message}</p>
            </div>
        `;
    }
}

function processEnhancedCheckout(bookingId) {
    const confirmBtn = document.getElementById('confirmCheckoutBtn');
    const originalText = confirmBtn.innerHTML;
    
    // Show loading state
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    // Prepare form data
    const formData = new FormData();
    formData.append('id_booking', bookingId);
    
    fetch('checkout-booking.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        // Hide modal first
        if (checkoutModal) {
            checkoutModal.hide();
        }
        
        if (data.success) {
            // Show success message
            showAlert(`✅ ${data.message}`, 'success');
            
            // Show additional info if available
            if (data.status_change) {
                setTimeout(() => {
                    showAlert(`📝 Status berubah: ${data.status_change}`, 'info');
                }, 2000);
            }
            
            // Reload page after delay
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        } else {
            showAlert(`❌ ${data.message}`, 'danger');
        }
    })
    .catch(error => {
        console.error('Checkout Error:', error);
        
        // Hide modal
        if (checkoutModal) {
            checkoutModal.hide();
        }
        
        showAlert('❌ Terjadi kesalahan saat melakukan checkout. Silakan coba lagi.', 'danger');
    })
    .finally(() => {
        // Reset button state
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalText;
        currentCheckoutBookingId = null;
    });
}

// 2. PERBAIKAN EVENT LISTENER untuk checkbox
// Ganti event listener yang ada untuk confirmCheckout (sekitar baris 50-80 di main.js):

function initializeEventListeners() {
    // ... existing code ...
    
    // Checkout confirmation - FIXED VERSION
    const confirmCheckbox = document.getElementById('confirmCheckout');
    const confirmBtn = document.getElementById('confirmCheckoutBtn');
    
    if (confirmCheckbox && confirmBtn) {
        confirmCheckbox.addEventListener('change', function() {
            confirmBtn.disabled = !this.checked;
            if (this.checked) {
                confirmBtn.classList.remove('btn-secondary');
                confirmBtn.classList.add('btn-warning');
            } else {
                confirmBtn.classList.remove('btn-warning');
                confirmBtn.classList.add('btn-secondary');
            }
        });
        
        confirmBtn.addEventListener('click', function() {
            if (currentCheckoutBookingId && confirmCheckbox.checked) {
                processEnhancedCheckout(currentCheckoutBookingId);
            } else if (!confirmCheckbox.checked) {
                showAlert('❌ Harap centang konfirmasi terlebih dahulu', 'warning');
            }
        });
    }
}

// CANCEL BOOKING FUNCTIONS
function cancelBooking(bookingId) {
    if (confirm('Apakah Anda yakin ingin membatalkan peminjaman ruangan ini?\n\nSlot waktu akan tersedia untuk pengguna lain.')) {
        processCancelBooking(bookingId);
    }
}

function processCancelBooking(bookingId) {
    showAlert('⏳ Membatalkan peminjaman...', 'info');
    
    fetch('cancel-booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `id_booking=${bookingId}`,
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(`✅ ${data.message}`, 'success');
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showAlert(`❌ ${data.message}`, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('❌ Terjadi kesalahan saat membatalkan peminjaman.', 'danger');
    });
}

// ACTIVATE BOOKING FUNCTIONS
function activateBooking(bookingId) {
    if (confirm('Apakah Anda yakin ingin mengaktifkan peminjaman ini sekarang?')) {
        processActivateBooking(bookingId);
    }
}

function processActivateBooking(bookingId) {
    showAlert('⏳ Mengaktifkan peminjaman...', 'info');
    
    fetch('activate_booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `booking_id=${bookingId}`,
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(`✅ ${data.message}`, 'success');
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showAlert(`❌ ${data.message}`, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('❌ Terjadi kesalahan saat mengaktifkan booking.', 'danger');
    });
}

// ADMIN FUNCTIONS
function approveBooking(bookingId, reason = '') {
    if (!reason) {
        reason = prompt('Masukkan alasan persetujuan (opsional):') || 'Approved by admin';
    }
    processAdminAction('approve', bookingId, reason);
}

function rejectBooking(bookingId, reason = '') {
    if (!reason) {
        reason = prompt('Masukkan alasan penolakan:');
        if (!reason) {
            showAlert('Alasan penolakan harus diisi', 'warning');
            return;
        }
    }
    processAdminAction('reject', bookingId, reason);
}

function processAdminAction(action, bookingId, reason) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('booking_id', bookingId);
    formData.append('reason', reason);
    
    showAlert(`⏳ Memproses ${action}...`, 'info');
    
    fetch('admin_booking_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(`✅ ${data.message}`, 'success');
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showAlert(`❌ ${data.message}`, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('❌ Terjadi kesalahan saat memproses aksi admin.', 'danger');
    });
}

// UTILITY FUNCTIONS
function showAlert(message, type = 'info', autoHide = true) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show fixed-top mx-auto mt-3`;
    alertDiv.style.maxWidth = '500px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.top = '80px';
    
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    if (autoHide) {
        setTimeout(() => {
            try {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            } catch (e) {
                alertDiv.remove();
            }
        }, 5000);
    }
}

function updateBookingRangeInfo() {
    const today = new Date();
    const oneMonthLater = new Date();
    oneMonthLater.setMonth(today.getMonth() + 1);
    
    const tglAwalBooking = document.getElementById('tgl-awal-booking');
    const tglAkhirBooking = document.getElementById('tgl-akhir-booking');
    
    if (tglAwalBooking && tglAkhirBooking) {
        tglAwalBooking.textContent = today.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
        tglAkhirBooking.textContent = oneMonthLater.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    }
}

// Update function untuk validasi tanggal booking
function validateBookingDates() {
    const today = new Date();
    const maxDate = new Date();
    maxDate.setMonth(today.getMonth() + 1); // 1 bulan ke depan
    
    const todayStr = today.toISOString().split('T')[0];
    const maxDateStr = maxDate.toISOString().split('T')[0];
    
    return {
        today: todayStr,
        maxDate: maxDateStr,
        isValidDate: function(dateStr) {
            return dateStr >= todayStr && dateStr <= maxDateStr;
        }
    };
}

// Update handleBookingSubmission function
function handleBookingSubmission(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const errorDiv = document.getElementById('bookingError');
    const successDiv = document.getElementById('bookingSuccess');
    const submitBtn = document.getElementById('submitBookingBtn');
    
    // Reset messages
    errorDiv.classList.add('d-none');
    successDiv.classList.add('d-none');
    
    // Validate dates
    const dateValidator = validateBookingDates();
    const bookingDate = formData.get('tanggal');
    
    if (!dateValidator.isValidDate(bookingDate)) {
        errorDiv.textContent = `Tanggal booking harus antara ${formatDate(dateValidator.today)} dan ${formatDate(dateValidator.maxDate)}`;
        errorDiv.classList.remove('d-none');
        return;
    }
    
    // Validate time range
    const startTime = document.getElementById('jam_mulai').value;
    const endTime = document.getElementById('jam_selesai').value;
    
    if (!startTime || !endTime) {
        errorDiv.textContent = 'Jam mulai dan jam selesai harus diisi.';
        errorDiv.classList.remove('d-none');
        return;
    }
    
    if (startTime >= endTime) {
        errorDiv.textContent = 'Jam selesai harus lebih dari jam mulai.';
        errorDiv.classList.remove('d-none');
        return;
    }
    
    // Validate booking duration (max 8 hours)
    const duration = calculateDuration(startTime, endTime);
    if (duration > 8) {
        errorDiv.textContent = 'Durasi booking maksimal 8 jam.';
        errorDiv.classList.remove('d-none');
        return;
    }
    
    // Check if booking is on weekend/holiday
    if (isWeekend(bookingDate)) {
        if (!confirm('Anda akan melakukan booking di hari weekend. Apakah Anda yakin?')) {
            return;
        }
    }
    
    // Validate other required fields
    const requiredFields = ['nama_acara', 'keterangan', 'nama_penanggungjawab', 'no_penanggungjawab'];
    for (let field of requiredFields) {
        const value = formData.get(field);
        if (!value || value.trim() === '') {
            errorDiv.textContent = `Field ${field.replace('_', ' ')} harus diisi.`;
            errorDiv.classList.remove('d-none');
            return;
        }
    }
    
    // Show loading state
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';
    
    // Submit booking
    fetch('process-booking.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            successDiv.textContent = data.message || 'Peminjaman berhasil disubmit!';
            successDiv.classList.remove('d-none');
            
            // Clear form
            form.reset();
            
            // Redirect after delay
            setTimeout(() => {
                if (bookingModal) {
                    bookingModal.hide();
                }
                window.location.href = `index.php?booking_success=1&date=${bookingDate}&room_id=${formData.get('id_ruang')}`;
            }, 2000);
        } else {
            errorDiv.textContent = data.message || 'Gagal memproses peminjaman.';
            errorDiv.classList.remove('d-none');
        }
    })
    .catch(error => {
        console.error('Booking error:', error);
        errorDiv.textContent = 'Terjadi kesalahan: ' + error.message;
        errorDiv.classList.remove('d-none');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

// Helper functions
function calculateDuration(startTime, endTime) {
    const start = new Date(`2000-01-01 ${startTime}`);
    const end = new Date(`2000-01-01 ${endTime}`);
    return (end - start) / (1000 * 60 * 60); // Convert to hours
}

function isWeekend(dateStr) {
    const date = new Date(dateStr);
    const day = date.getDay();
    return day === 0 || day === 6; // Sunday = 0, Saturday = 6
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long', 
        year: 'numeric'
    });
}

// Update calendar display untuk disable tanggal di luar range
function updateCalendarDateLimits() {
    const dateValidator = validateBookingDates();
    const calendarDays = document.querySelectorAll('.mini-calendar-day');
    
    calendarDays.forEach(day => {
        const dateStr = day.dataset.date;
        if (dateStr && !dateValidator.isValidDate(dateStr)) {
            day.classList.add('out-of-range');
            day.style.pointerEvents = 'none';
            day.title = 'Di luar rentang booking yang diizinkan';
        }
    });
}

// Update booking form date input limits
function setBookingFormDateLimits() {
    const dateValidator = validateBookingDates();
    const dateInput = document.getElementById('booking_date');
    
    if (dateInput) {
        dateInput.min = dateValidator.today;
        dateInput.max = dateValidator.maxDate;
    }
    
    // Also update any other date inputs in booking modal
    const jamMulaiInput = document.getElementById('jam_mulai');
    const jamSelesaiInput = document.getElementById('jam_selesai');
    
    if (jamMulaiInput && jamSelesaiInput) {
        // Set business hours limits
        jamMulaiInput.min = "07:00";
        jamMulaiInput.max = "21:00";
        jamSelesaiInput.min = "08:00"; 
        jamSelesaiInput.max = "22:00";
    }
}

// Call these functions when page loads
document.addEventListener('DOMContentLoaded', function() {
    updateCalendarDateLimits();
    setBookingFormDateLimits();
    
    // Update limits when booking modal opens
    const bookingModalElement = document.getElementById('bookingModal');
    if (bookingModalElement) {
        bookingModalElement.addEventListener('show.bs.modal', setBookingFormDateLimits);
    }
});

// Add real-time validation to booking form
function addRealTimeValidation() {
    const bookingDateInput = document.getElementById('booking_date');
    const jamMulaiInput = document.getElementById('jam_mulai');
    const jamSelesaiInput = document.getElementById('jam_selesai');
    
    if (bookingDateInput) {
        bookingDateInput.addEventListener('change', function() {
            const dateValidator = validateBookingDates();
            const errorDiv = document.getElementById('bookingError');
            
            if (!dateValidator.isValidDate(this.value)) {
                errorDiv.textContent = `Tanggal harus antara ${formatDate(dateValidator.today)} dan ${formatDate(dateValidator.maxDate)}`;
                errorDiv.classList.remove('d-none');
                this.setCustomValidity('Tanggal di luar rentang yang diizinkan');
            } else {
                errorDiv.classList.add('d-none');
                this.setCustomValidity('');
            }
        });
    }
    
    if (jamSelesaiInput && jamMulaiInput) {
        function validateTimeRange() {
            const startTime = jamMulaiInput.value;
            const endTime = jamSelesaiInput.value;
            const errorDiv = document.getElementById('bookingError');
            
            if (startTime && endTime) {
                if (startTime >= endTime) {
                    errorDiv.textContent = 'Jam selesai harus lebih dari jam mulai';
                    errorDiv.classList.remove('d-none');
                    jamSelesaiInput.setCustomValidity('Jam selesai harus lebih dari jam mulai');
                } else {
                    const duration = calculateDuration(startTime, endTime);
                    if (duration > 8) {
                        errorDiv.textContent = 'Durasi booking maksimal 8 jam';
                        errorDiv.classList.remove('d-none');
                        jamSelesaiInput.setCustomValidity('Durasi terlalu lama');
                    } else {
                        errorDiv.classList.add('d-none');
                        jamSelesaiInput.setCustomValidity('');
                    }
                }
            }
        }
        
        jamMulaiInput.addEventListener('change', validateTimeRange);
        jamSelesaiInput.addEventListener('change', validateTimeRange);
    }
}

function addCustomStyles() {
    const style = document.createElement('style');
    style.textContent = `
        .floating-notification {
            animation: slideInRight 0.5s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .btn-pulse {
            animation: btnPulse 2s infinite;
        }
        
        @keyframes btnPulse {
            0% { 
                box-shadow: 0 0 0 0 rgba(40, 167, 69,
                transform: scale(1);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
                transform: scale(1.05);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
                transform: scale(1);
            }
        }
        
        .activate-btn {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            font-weight: 600;
        }
        
        .activate-btn:hover {
            background: linear-gradient(135deg, #218838, #1e9b7f);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
    `;
    document.head.appendChild(style);
    
    // Show auto-approved notification
    showAutoApprovedNotification();
    
    // Enhanced booking status checker
    enhancedBookingStatusChecker();
};

// Enhanced status checker dengan self-activation detection
function enhancedBookingStatusChecker() {
    // Check every minute for bookings that can be self-activated
    setInterval(() => {
        const approvedBookings = document.querySelectorAll('.table-success .booking-info');
        
        approvedBookings.forEach(bookingElement => {
            // Extract booking data from element
            const bookingId = bookingElement.dataset.bookingId;
            const userId = bookingElement.dataset.userId;
            
            // Show activation button if eligible
            if (bookingId && userId && userId == window.userId) {
                showSelfActivationIndicator(bookingElement);
            }
        });
    }, 60000); // Check every minute
}

// Show indicator that booking can be self-activated
function showSelfActivationIndicator(bookingElement) {
    // Add blinking indicator
    if (!bookingElement.querySelector('.self-activate-indicator')) {
        const indicator = document.createElement('div');
        indicator.className = 'self-activate-indicator mt-2 p-2 bg-info text-white rounded';
        indicator.innerHTML = `
            <i class="fas fa-hand-pointer blink me-2"></i>
            <strong>Siap Diaktifkan!</strong> Klik tombol aktifkan untuk mulai menggunakan ruangan.
        `;
        bookingElement.appendChild(indicator);
    }
}

// Function to check if user can activate booking
function checkUserCanActivate(booking) {
    const currentDateTime = new Date();
    const bookingDateTime = new Date(booking.tanggal + ' ' + booking.jam_mulai);
    const timeDiffMinutes = (bookingDateTime.getTime() - currentDateTime.getTime()) / (1000 * 60);
    
    // Rules for self-activation
    // Tambahkan ke main.js - Logika untuk menampilkan tombol yang sesuai

// Function untuk menentukan tombol yang tepat berdasarkan status booking dan waktu
function determineBookingAction(booking) {
    const currentDateTime = new Date();
    const currentDate = currentDateTime.toISOString().split('T')[0];
    const currentTime = currentDateTime.toTimeString().split(' ')[0];
    
    const bookingDate = booking.tanggal;
    const bookingStartTime = booking.jam_mulai;
    const bookingEndTime = booking.jam_selesai;
    
    // Check if booking time has passed
    const isBookingTimeExpired = () => {
        if (bookingDate < currentDate) {
            return true; // Booking date has passed
        } else if (bookingDate === currentDate) {
            return currentTime > bookingEndTime; // Current time is after booking end time
        }
        return false; // Future booking
    };
    
    // Check if booking is currently active (within booking time)
    const isBookingCurrentlyActive = () => {
        if (bookingDate === currentDate) {
            return currentTime >= bookingStartTime && currentTime <= bookingEndTime;
        }
        return false;
    };
    
    // Return appropriate action based on conditions
    if (booking.status === 'active') {
        if (isBookingCurrentlyActive() || isBookingTimeExpired()) {
            return {
                action: 'checkout',
                buttonClass: 'btn-info checkout-btn',
                buttonText: '<i class="fas fa-sign-out-alt"></i> Checkout',
                buttonTitle: 'Checkout ruangan'
            };
        } else {
            return {
                action: 'info',
                buttonClass: 'btn-secondary',
                buttonText: '<i class="fas fa-info"></i> Aktif',
                buttonTitle: 'Booking sedang aktif'
            };
        }
    } else if (booking.status === 'approve') {
        if (isBookingTimeExpired()) {
            return {
                action: 'expired',
                buttonClass: 'btn-warning',
                buttonText: '<i class="fas fa-clock"></i> Expired',
                buttonTitle: 'Booking sudah berakhir'
            };
        } else if (isBookingCurrentlyActive()) {
            // User can activate their own booking during booking time
            if (booking.id_user == window.userId) {
                return {
                    action: 'activate',
                    buttonClass: 'btn-success activate-btn',
                    buttonText: '<i class="fas fa-play"></i> Aktifkan',
                    buttonTitle: 'Aktifkan booking sekarang'
                };
            } else {
                return {
                    action: 'info',
                    buttonClass: 'btn-info',
                    buttonText: '<i class="fas fa-check"></i> Disetujui',
                    buttonTitle: 'Booking disetujui, menunggu aktivasi'
                };
            }
        } else {
            // Future booking or not yet time
            if (booking.id_user == window.userId) {
                return {
                    action: 'cancel',
                    buttonClass: 'btn-danger cancel-booking-btn',
                    buttonText: '<i class="fas fa-times"></i> Batalkan',
                    buttonTitle: 'Batalkan peminjaman'
                };
            } else {
                return {
                    action: 'info',
                    buttonClass: 'btn-info',
                    buttonText: '<i class="fas fa-check"></i> Disetujui',
                    buttonTitle: 'Booking disetujui'
                };
            }
        }
    } else if (booking.status === 'pending') {
        if (isBookingTimeExpired()) {
            return {
                action: 'expired',
                buttonClass: 'btn-warning',
                buttonText: '<i class="fas fa-clock"></i> Expired',
                buttonTitle: 'Booking sudah berakhir'
            };
        } else if (booking.id_user == window.userId) {
            return {
                action: 'cancel',
                buttonClass: 'btn-danger cancel-booking-btn',
                buttonText: '<i class="fas fa-times"></i> Batalkan',
                buttonTitle: 'Batalkan peminjaman'
            };
        } else {
            return {
                action: 'info',
                buttonClass: 'btn-warning',
                buttonText: '<i class="fas fa-clock"></i> Pending',
                buttonTitle: 'Menunggu persetujuan'
            };
        }
    } else {
        return {
            action: 'info',
            buttonClass: 'btn-secondary',
            buttonText: '<i class="fas fa-info"></i> ' + booking.status,
            buttonTitle: 'Status: ' + booking.status
        };
    }
}

// Function untuk render tombol booking yang sesuai
function renderBookingButton(booking, container) {
    const actionInfo = determineBookingAction(booking);
    
    const button = document.createElement('button');
    button.className = `btn btn-sm ${actionInfo.buttonClass}`;
    button.innerHTML = actionInfo.buttonText;
    button.title = actionInfo.buttonTitle;
    button.dataset.bookingId = booking.id_booking;
    
    // Add event listener based on action
    switch (actionInfo.action) {
        case 'checkout':
            button.onclick = () => showCheckoutModal(booking.id_booking);
            break;
        case 'cancel':
            button.onclick = () => cancelBooking(booking.id_booking);
            break;
        case 'activate':
            button.onclick = () => showSelfActivationModal(booking.id_booking);
            break;
        case 'expired':
        case 'info':
        default:
            button.disabled = true;
            break;
    }
    
    // Clear container and add button
    container.innerHTML = '';
    container.appendChild(button);
    
    return button;
}

// Function untuk update semua tombol booking di halaman
function updateAllBookingButtons() {
    const bookingElements = document.querySelectorAll('[data-booking-info]');
    
    bookingElements.forEach(element => {
        try {
            const bookingInfo = JSON.parse(element.dataset.bookingInfo);
            const buttonContainer = element.querySelector('.booking-actions') || 
                                   element.querySelector('.btn-container') ||
                                   element;
            
            if (buttonContainer) {
                renderBookingButton(bookingInfo, buttonContainer);
            }
        } catch (e) {
            console.error('Error parsing booking info:', e);
        }
    });
}

// Auto-update tombol setiap 30 detik
setInterval(updateAllBookingButtons, 30000);

// Update saat halaman dimuat
document.addEventListener('DOMContentLoaded', function() {
    // Set user ID untuk logika button
    window.userId = document.body.dataset.userId || 
                    (window.session && window.session.user_id);
    
    // Update tombol saat halaman dimuat
    setTimeout(updateAllBookingButtons, 1000);
});

// Function untuk menampilkan notifikasi checkout otomatis
function showAutoCheckoutNotification() {
    const urlParams = new URLSearchParams(window.location.search);
    
    if (urlParams.get('auto_checkout') === '1') {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info alert-dismissible fade show fixed-top mx-auto mt-3';
        notification.style.cssText = `
            max-width: 500px;
            z-index: 1070;
            top: 80px;
        `;
        
        notification.innerHTML = `
            <i class="fas fa-info-circle me-2"></i>
            <strong>Auto Checkout:</strong> Sistem telah melakukan checkout otomatis untuk booking yang sudah berakhir.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            try {
                const bsAlert = new bootstrap.Alert(notification);
                bsAlert.close();
            } catch (e) {
                notification.remove();
            }
        }, 5000);
    }
}

// Panggil saat DOM ready
document.addEventListener('DOMContentLoaded', showAutoCheckoutNotification);
    
    return false;
}

function processLogin() {
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('loginError');
    
    // Reset error message
    errorDiv.classList.add('d-none');
    
    // Perform AJAX login
    fetch('process-login.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`,
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close login modal
            loginModal.hide();
            
            // Add logged-in class to body
            document.body.classList.add('logged-in');
            
            // Store role in data attribute
            document.body.dataset.userRole = data.user.role;
            
            // Tampilkan pesan sukses
            showAlert(`Login berhasil! Selamat datang, ${data.user.email} (${data.user.role})`, 'success');
            
            // Check if there's a pending booking
            const pendingBooking = sessionStorage.getItem('pendingBooking');
            
            if (pendingBooking) {
                const bookingData = JSON.parse(pendingBooking);
                showBookingForm(bookingData.date, bookingData.time, bookingData.roomId);
                sessionStorage.removeItem('pendingBooking');
            } else if (data.redirect) {
                // Redirect jika tidak ada pending booking
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1000);
            } else {
                // Reload page to show user-specific content
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else {
            // Show error message
            errorDiv.textContent = data.message || 'Login gagal. Silakan coba lagi.';
            errorDiv.classList.remove('d-none');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errorDiv.textContent = 'Terjadi kesalahan. Silakan coba lagi.';
        errorDiv.classList.remove('d-none');
    });
}


function cancelBooking(bookingId) {
    document.getElementById('confirmationMessage').textContent = 'Apakah Anda yakin ingin membatalkan peminjaman ruangan ini?';
    document.getElementById('confirmationId').value = bookingId;
    document.getElementById('confirmationType').value = 'cancel';
    confirmationModal.show();
}

function processCancelBooking(bookingId) {
    fetch('cancel-booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `id_booking=${bookingId}`,
    })
    .then(response => response.json())
    .then(data => {
        confirmationModal.hide();
        
        if (data.success) {
            window.location.href = 'index.php?booking_cancelled=1';
        } else {
            showAlert(data.message || 'Gagal membatalkan peminjaman.', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        confirmationModal.hide();
        showAlert('Terjadi kesalahan. Silakan coba lagi.', 'danger');
    });
}

function checkoutBooking(bookingId) {
    document.getElementById('confirmationMessage').textContent = 'Apakah Anda yakin ingin melakukan checkout untuk ruangan ini?';
    document.getElementById('confirmationId').value = bookingId;
    document.getElementById('confirmationType').value = 'checkout';
    confirmationModal.show();
}

// Function untuk memeriksa booking yang sudah lewat waktu persetujuan dan mengubah status menjadi "Used"
function updateBookingStatus() {
    const currentTime = new Date();
    const bookings = document.querySelectorAll('.booking'); // Pastikan setiap elemen booking punya kelas 'booking'

    bookings.forEach(booking => {
        const bookingDate = new Date(booking.dataset.bookingDate); // Get booking date from data attribute
        const status = booking.querySelector('.status'); // The status element

        // Jika status masih 'Pending' dan waktu booking sudah lewat, ubah menjadi 'Used' (merah)
        if (status.textContent === 'Pending' && currentTime > bookingDate) {
            status.textContent = 'Used';
            status.classList.add('bg-danger');
            showCheckoutButton(booking);
        }
    });
}

// Menampilkan tombol checkout jika status berubah menjadi "Used"
function showCheckoutButton(booking) {
    const checkoutButton = booking.querySelector('.checkout-btn');
    checkoutButton.style.display = 'block';  // Menampilkan tombol checkout
    checkoutButton.addEventListener('click', function() {
        processCheckout(booking.dataset.bookingId); // Proses checkout
    });
}

// Proses checkout setelah tombol checkout ditekan
function processCheckout(bookingId) {
    fetch('checkout-booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ id_booking: bookingId }),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("✅ Booking selesai dan berhasil di-checkout!");
            window.location.reload(); // Reload halaman untuk memperbarui status
        } else {
            alert("❌ Gagal melakukan checkout");
        }
    })
    .catch(error => {
        console.error("Error checkout:", error);
        alert("Terjadi kesalahan saat checkout");
    });
}


// Admin-specific functions
function updateRoomNamePrefix() {
    const selectedGedung = document.getElementById('id_gedung_new');
    const roomPrefix = document.getElementById('room-prefix');
    
    if (selectedGedung && roomPrefix) {
        if (selectedGedung.selectedIndex > 0) {
            const gedungCode = selectedGedung.options[selectedGedung.selectedIndex].getAttribute('data-code');
            roomPrefix.textContent = gedungCode + '-';
        } else {
            roomPrefix.textContent = '?-';
        }
    }
}

function combineRoomName() {
    const gedungSelect = document.getElementById('id_gedung_new');
    const roomNumber = document.getElementById('room_number_new').value;
    const namaRuangInput = document.getElementById('nama_ruang_new');
    
    if (gedungSelect && roomNumber && namaRuangInput) {
        if (gedungSelect.selectedIndex > 0 && roomNumber) {
            const gedungCode = gedungSelect.options[gedungSelect.selectedIndex].getAttribute('data-code');
            namaRuangInput.value = gedungCode + '-' + roomNumber;
        }
    }
}

function updateRoomNamePrefixEdit(roomId) {
    const selectedGedung = document.getElementById('id_gedung' + roomId);
    const roomPrefix = document.getElementById('room-prefix' + roomId);
    
    if (selectedGedung && roomPrefix) {
        if (selectedGedung.selectedIndex > 0) {
            const gedungCode = selectedGedung.options[selectedGedung.selectedIndex].getAttribute('data-code');
            roomPrefix.textContent = gedungCode + '-';
        } else {
            roomPrefix.textContent = '?-';
        }
    }
}

function combineRoomNameEdit(roomId) {
    const gedungSelect = document.getElementById('id_gedung' + roomId);
    const roomNumber = document.getElementById('room_number' + roomId).value;
    const namaRuangInput = document.getElementById('nama_ruang' + roomId);
    
    if (gedungSelect && roomNumber && namaRuangInput) {
        if (gedungSelect.selectedIndex > 0 && roomNumber) {
            const gedungCode = gedungSelect.options[gedungSelect.selectedIndex].getAttribute('data-code');
            namaRuangInput.value = gedungCode + '-' + roomNumber;
        }
    }
}

// UI Helper functions
function updateLoggedInUI(user) {
    // Update header with user info
    const userInfoElement = document.getElementById('userInfo');
    if (userInfoElement) {
        userInfoElement.innerHTML = `
            <span class="me-2">${user.email}</span>
            <div class="dropdown d-inline-block">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="my_bookings.php">Peminjaman Saya</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                </ul>
            </div>
        `;
    }
}

function showAlert(message, type = 'info') {
    // Create alert div
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show fixed-top mx-auto mt-3`;
    alertDiv.style.maxWidth = '500px';
    alertDiv.style.zIndex = '9999';
    
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    // Add to body
    document.body.appendChild(alertDiv);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        try {
            const bsAlert = new bootstrap.Alert(alertDiv);
            bsAlert.close();
        } catch (e) {
            alertDiv.remove();
        }
    }, 5000);
}

