---
title: "Login"
sidebar: true
---

You can log in for extra options.

<div class="card shadow-lg rounded-3">
<div class="card-body">
<h4 class="card-title text-center mb-4">Login</h4>
<form id="login-form">
<div class="mb-3">
<label for="email" class="form-label">Email address</label>
<input type="email" class="form-control" id="email" placeholder="Enter email" required>
</div>
<div class="mb-3">
<label for="password" class="form-label">Password</label>
<input type="password" class="form-control" id="password" placeholder="Password" required>
</div>
<button type="submit" class="btn btn-primary w-100">Login</button></form>
<div id="error-msg" class="alert alert-danger mt-3 d-none"></div>
</div>
</div>

<div id="app"></div>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>

<script src="https://cdn.auth0.com/js/auth0/9.28.0/auth0.min.js"></script>

<script>
// Configure Auth0 client
var webAuth = new auth0.WebAuth({
domain: 'dev-hij2adphqgx0e2ay.us.auth0.com',   // replace with your Auth0 domain
clientID: 'xqCwC4XzNpUGVdfw7yCJXjpDJNwZ6Baf',  
redirectUri: 'https://watdev-eu.github.io/model-ui',
responseType: 'token id_token',
scope: 'openid profile email'});

document.getElementById("login-form").addEventListener("submit", function(e) {
e.preventDefault();

var email = document.getElementById("email").value;
var password = document.getElementById("password").value;

webAuth.login({
realm: 'Username-Password-Authentication',
username: email,
password: password
}, function(err) {
if (err) {
document.getElementById("error-msg").textContent = err.description || "Login failed";
          document.getElementById("error-msg").classList.remove("d-none");
}})});
webAuth.parseHash(function(err, authResult) {
if (authResult && authResult.accessToken && authResult.idToken) {
window.location.hash = "";
alert("Login successful! Access Token: " + authResult.accessToken);
} else if (err) {
console.error("Error parsing hash:", err)}});
</script>
