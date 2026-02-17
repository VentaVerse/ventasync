<section>
    <h3 class="section-title mt-0">Delete Account</h3>
    <p class="text-secondary text-sm mb-16">Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.</p>

    <button class="btn danger" id="delete-account-btn" type="button">Delete Account</button>

    <div id="delete-account-modal" class="modal-backdrop">
        <div class="modal">
            <form method="post" action="{{ route('profile.destroy') }}">
                @csrf
                @method('delete')

                <div class="modal-header">
                    <h3>Are you sure you want to delete your account?</h3>
                    <button type="button" class="modal-close" onclick="document.getElementById('delete-account-modal').classList.remove('active')">&times;</button>
                </div>

                <p class="text-secondary text-sm mb-16">Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.</p>

                <div class="mb-16">
                    <label for="delete_password">Password</label>
                    <input id="delete_password" name="password" type="password" class="input" placeholder="Password">
                    @if($errors->userDeletion->has('password'))
                        <div class="field-error">{{ $errors->userDeletion->first('password') }}</div>
                    @endif
                </div>

                <div class="d-flex justify-end gap-8">
                    <button type="button" class="btn secondary" onclick="document.getElementById('delete-account-modal').classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn danger">Delete Account</button>
                </div>
            </form>
        </div>
    </div>
</section>

@push('scripts')
<script>
(function(){
    var btn = document.getElementById('delete-account-btn');
    var modal = document.getElementById('delete-account-modal');
    if(btn && modal){
        btn.addEventListener('click', function(){ modal.classList.add('active'); });
        modal.addEventListener('click', function(e){
            if(e.target === modal) modal.classList.remove('active');
        });
    }
    @if($errors->userDeletion->isNotEmpty())
        if(modal) modal.classList.add('active');
    @endif
})();
</script>
@endpush
